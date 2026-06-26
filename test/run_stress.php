#!/usr/bin/env php
<?php

/**
 * run_stress.php – Runner standalone per gli stress test di JsonDatabase.
 *
 * Uso:
 *   php run_stress.php [opzioni]
 *
 * Opzioni:
 *   --suite=all|insert|read|write|compact|secondary|batch|compare
 *                          Suite da eseguire (default: all)
 *   --n=10000              Numero di record per suite (default: 10000)
 *   --mode=plain|encrypt|compare
 *                          plain   → dati in chiaro (default)
 *                          encrypt → campi sensibili cifrati con AES-256-CBC
 *                          compare → esegue entrambe le modalità e confronta
 *   --insert=single|batch|both
 *                          Strategia di inserimento (default: both)
 *                          single → insert() record per record
 *                          batch  → insertBatch() in blocchi da --chunk
 *                          both   → esegue entrambi e mostra speedup
 *   --chunk=500            Dimensione del blocco per insertBatch (default: 500)
 *
 * Esempi:
 *   php run_stress.php
 *   php run_stress.php --suite=batch --n=50000 --chunk=1000
 *   php run_stress.php --suite=insert --mode=compare --n=5000
 *   php run_stress.php --suite=compare --n=20000 --insert=both
 *   php run_stress.php --suite=secondary --mode=encrypt --n=5000
 */

declare(strict_types=1);

require_once __DIR__ . '/src/jdb.php';
require_once __DIR__ . '/tests/Support/DataGenerator.php';

use Tests\Support\DataGenerator;

// ===========================================================================
// CLI args
// ===========================================================================
$opts      = getopt('', ['suite:', 'n:', 'mode:', 'insert:', 'chunk:']);
$suite     = $opts['suite']  ?? 'all';
$N         = (int)($opts['n'] ?? 10_000);
$mode      = $opts['mode']   ?? 'plain';
$insertArg = $opts['insert'] ?? 'both';
$chunkSz   = max(1, (int)($opts['chunk'] ?? 500));

// Validate
$validModes   = ['plain', 'encrypt', 'compare'];
$validInserts = ['single', 'batch', 'both'];
$validSuites  = ['all', 'insert', 'read', 'write', 'compact', 'secondary', 'batch', 'compare'];

if (!in_array($mode,      $validModes,   true)) { fwrite(STDERR, "Modalità non valida: $mode\n");       exit(1); }
if (!in_array($insertArg, $validInserts, true)) { fwrite(STDERR, "Insert non valido: $insertArg\n");    exit(1); }
if (!in_array($suite,     $validSuites,  true)) { fwrite(STDERR, "Suite non valida: $suite\n");         exit(1); }

// ===========================================================================
// Encryption layer
// ===========================================================================

/**
 * Cifra i campi sensibili di un record con AES-256-CBC.
 * Restituisce il record con i campi cifrati in base64 e il flag __encrypted=1.
 * Campi cifrati: name, email, address (se presenti).
 */
function encryptRecord(array $data, string $key): array
{
    static $fields = ['name', 'email', 'address'];
    $iv = random_bytes(16);
    foreach ($fields as $f) {
        if (!isset($data[$f])) continue;
        $ct = openssl_encrypt((string)$data[$f], 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $data[$f] = base64_encode($iv . $ct);
    }
    $data['__encrypted'] = 1;
    return $data;
}

/**
 * Decifra i campi sensibili di un record.
 * No-op se il record non ha __encrypted=1.
 */
function decryptRecord(array $data, string $key): array
{
    if (empty($data['__encrypted'])) return $data;
    static $fields = ['name', 'email', 'address'];
    foreach ($fields as $f) {
        if (!isset($data[$f])) continue;
        $raw = base64_decode($data[$f], true);
        if ($raw === false || strlen($raw) < 17) continue;
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $pt = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($pt !== false) $data[$f] = $pt;
    }
    return $data;
}

/** Controlla che openssl sia disponibile per la modalità encrypt. */
function checkEncryptSupport(): bool
{
    if (!function_exists('openssl_encrypt')) {
        echo "  ⚠  Estensione openssl non disponibile — modalità encrypt saltata.\n";
        return false;
    }
    return true;
}

// Chiave AES derivata in modo deterministico per i test (non usare in produzione).
$ENCRYPT_KEY = hash('sha256', 'jdb-stress-test-key-v1', true); // 32 byte raw

// ===========================================================================
// Micro-framework di misurazione
// ===========================================================================
$metrics = [];

function measure(string $label, callable $fn, int $ops = 1, string $tag = ''): mixed
{
    global $metrics;
    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $start     = hrtime(true);

    $result = $fn();

    $elapsed  = (hrtime(true) - $start) / 1_000_000;
    $memAfter = memory_get_usage(true);
    $peak     = memory_get_peak_usage(true);

    $key = $tag ? "$label [$tag]" : $label;
    $metrics[$key] = [
        'label'        => $label,
        'tag'          => $tag,
        'elapsed_ms'   => round($elapsed, 2),
        'ops'          => $ops,
        'throughput'   => $ops > 0 ? round($ops / ($elapsed / 1000), 0) : 0,
        'mem_delta_kb' => round(($memAfter - $memBefore) / 1024, 1),
        'peak_mem_mb'  => round($peak / (1024 * 1024), 2),
    ];

    $tagStr = $tag ? " \033[90m[$tag]\033[0m" : '';
    printf(
        "  %-40s %8.0f ms  |  %9s ops/s  |  Δmem %+7.0f KB  |  peak %6.1f MB%s\n",
        $label,
        $elapsed,
        number_format((int)$metrics[$key]['throughput']),
        $metrics[$key]['mem_delta_kb'],
        $metrics[$key]['peak_mem_mb'],
        $tagStr
    );

    return $result;
}

function freshDir(string $prefix = 'jdb'): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '_' . uniqid();
    mkdir($dir, 0755, true);
    return $dir;
}

function deleteDir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($dir);
}

/**
 * Insert singoli record uno alla volta.
 * Se $encKey è fornita, cifra ogni record prima dell'insert.
 */
function singleInsert(JsonDatabase $db, array $records, ?string $encKey = null): array
{
    $ids = [];
    foreach ($records as $rec) {
        if ($encKey !== null) $rec = encryptRecord($rec, $encKey);
        $id = $db->insert($rec);
        if ($id !== false) $ids[] = $id;
    }
    return $ids;
}

/**
 * Insert tramite insertBatch() in blocchi da $chunkSz record.
 * Se $encKey è fornita, cifra ogni record prima dell'insert.
 */
function batchInsert(JsonDatabase $db, array $records, int $chunkSz, ?string $encKey = null): array
{
    $ids = [];
    foreach (array_chunk($records, $chunkSz) as $chunk) {
        if ($encKey !== null) {
            $chunk = array_map(fn($r) => encryptRecord($r, $encKey), $chunk);
        }
        $res = $db->insertBatch($chunk);
        if ($res !== false) {
            $ids = array_merge($ids, $res['ids']);
        }
    }
    return $ids;
}

function section(string $title): void
{
    $line = str_repeat('─', 84);
    echo "\n$line\n  $title\n$line\n";
}

// ===========================================================================
// Helpers per eseguire una suite nelle modalità richieste
// ===========================================================================

/**
 * Esegue $fn nelle modalità plain/encrypt/compare secondo $mode.
 * $fn riceve ($encKey, $tag) e deve usarli nei measure().
 */
function runModes(string $mode, string $encKey, callable $fn): void
{
    if ($mode === 'plain' || $mode === 'compare') {
        $fn(null, 'plain');
    }
    if ($mode === 'encrypt' || $mode === 'compare') {
        if (checkEncryptSupport()) {
            $fn($encKey, 'enc');
        }
    }
}

/**
 * Esegue insert in modalità single/batch/both e restituisce [label => [ids, ms]].
 * Usato dalla suite compare e dalle altre suite quando $insertArg != 'single'.
 */
function runInsertModes(
    string $insertArg,
    JsonDatabase $db,
    array $records,
    int $chunkSz,
    ?string $encKey,
    string $tag,
    string $baseLabel
): array
{
    $results = [];

    if ($insertArg === 'single' || $insertArg === 'both') {
        $t0  = hrtime(true);
        $ids = singleInsert($db, $records, $encKey);
        $ms  = (hrtime(true) - $t0) / 1_000_000;
        measure($baseLabel . '_single', fn() => null, count($ids), $tag);
        // Override metric with real time (measure ran the no-op)
        global $metrics;
        $k = $tag ? "$baseLabel\_single [$tag]" : "$baseLabel\_single";
        // Re-measure properly:
        $results['single'] = ['ids' => $ids, 'ms' => $ms];
    }

    if ($insertArg === 'batch' || $insertArg === 'both') {
        $db2  = null; // caller must provide a fresh db if running both
        $t0   = hrtime(true);
        $ids2 = batchInsert($db, $records, $chunkSz, $encKey);
        $ms2  = (hrtime(true) - $t0) / 1_000_000;
        $results['batch'] = ['ids' => $ids2, 'ms' => $ms2];
    }

    return $results;
}

// ===========================================================================
// SUITE: INSERT
// ===========================================================================
if (in_array($suite, ['all', 'insert'])) {

    $runOnce = function(?string $encKey, string $tag) use ($N, $insertArg, $chunkSz) {
        section("INSERT STRESS (N=$N, mode=$tag, insert=$insertArg)");
        $dir     = freshDir("ins_$tag");
        $records = DataGenerator::makeBatch($N);

        if ($insertArg === 'single' || $insertArg === 'both') {
            $db = new JsonDatabase("seq_$tag", $dir);
            measure("insert_single_{$N}", function () use ($db, $records, $encKey) {
                singleInsert($db, $records, $encKey);
            }, $N, $tag);

            $dataSz = round(filesize("$dir/seq_{$tag}.jsonl.php") / 1024, 1);
            $idxSz  = round(filesize("$dir/seq_{$tag}.index.php")  / 1024, 1);
            echo "  → single: {$dataSz} KB data / {$idxSz} KB index\n";
        }

        if ($insertArg === 'batch' || $insertArg === 'both') {
            $db2 = new JsonDatabase("bat_$tag", $dir);
            measure("insert_batch_{$N}_chunk{$chunkSz}", function () use ($db2, $records, $chunkSz, $encKey) {
                batchInsert($db2, $records, $chunkSz, $encKey);
            }, $N, $tag);

            $dataSz2 = round(filesize("$dir/bat_{$tag}.jsonl.php") / 1024, 1);
            $idxSz2  = round(filesize("$dir/bat_{$tag}.index.php")  / 1024, 1);
            echo "  → batch:  {$dataSz2} KB data / {$idxSz2} KB index\n";
        }

        // Payload pesante
        $heavy = DataGenerator::makeBatch((int)($N / 5), heavy: true);
        $dbH   = new JsonDatabase("heavy_$tag", $dir);
        measure("insert_heavy_" . (int)($N / 5), function () use ($dbH, $heavy, $encKey) {
            singleInsert($dbH, $heavy, $encKey);
        }, count($heavy), $tag);

        deleteDir($dir);
    };

    runModes($mode, $ENCRYPT_KEY, $runOnce);
}

// ===========================================================================
// SUITE: BATCH (benchmark dedicato insertBatch vs single insert)
// ===========================================================================
if (in_array($suite, ['all', 'batch'])) {
    section("BATCH INSERT BENCHMARK (N=$N, chunk=$chunkSz)");

    $runBatch = function(?string $encKey, string $tag) use ($N, $chunkSz) {
        $dir     = freshDir("batch_$tag");
        $records = DataGenerator::makeBatch($N);

        // --- Single insert ---
        $dbS = new JsonDatabase("single_$tag", $dir);
        $msSingle = 0.0;
        measure("single_insert_{$N}", function () use ($dbS, $records, $encKey, &$msSingle) {
            $t0 = hrtime(true);
            singleInsert($dbS, $records, $encKey);
            $msSingle = (hrtime(true) - $t0) / 1_000_000;
        }, $N, $tag);

        // --- Batch insert: varie dimensioni di chunk ---
        $chunks = array_unique([$chunkSz, 100, 500, 1000, $N]);
        sort($chunks);
        $msBatch = [];
        foreach ($chunks as $cs) {
            if ($cs > $N) continue;
            $dbB    = new JsonDatabase("batch_{$tag}_{$cs}", $dir);
            $localMs = 0.0;
            measure("batch_insert_{$N}_chunk{$cs}", function () use ($dbB, $records, $cs, $encKey, &$localMs) {
                $t0 = hrtime(true);
                batchInsert($dbB, $records, $cs, $encKey);
                $localMs = (hrtime(true) - $t0) / 1_000_000;
            }, $N, $tag);
            $msBatch[$cs] = $localMs;
        }

        // Speedup summary
        if ($msSingle > 0) {
            echo "\n  Speedup insertBatch vs single [$tag]:\n";
            foreach ($msBatch as $cs => $ms) {
                $speedup = $msSingle > 0 ? round($msSingle / max($ms, 0.01), 2) : '—';
                printf("    chunk=%-6d  %6.0f ms  speedup=%.2fx\n", $cs, $ms, (float)$speedup);
            }
        }

        deleteDir($dir);
    };

    runModes($mode, $ENCRYPT_KEY, $runBatch);
}

// ===========================================================================
// SUITE: COMPARE (single vs batch + plain vs encrypt, fianco a fianco)
// ===========================================================================
if (in_array($suite, ['all', 'compare'])) {
    section("CONFRONTO INSERIMENTO (N=$N, chunk=$chunkSz)");

    $dir     = freshDir('compare');
    $records = DataGenerator::makeBatch($N);

    $results = [];

    // Plain single
    $dbPS = new JsonDatabase('plain_single', $dir);
    measure("single plain", function () use ($dbPS, $records) {
        return singleInsert($dbPS, $records, null);
    }, $N, 'plain');
    $results['plain_single'] = $metrics["single plain [plain]"]['elapsed_ms'];

    // Plain batch
    $dbPB = new JsonDatabase('plain_batch', $dir);
    measure("batch plain chunk=$chunkSz", function () use ($dbPB, $records, $chunkSz) {
        return batchInsert($dbPB, $records, $chunkSz, null);
    }, $N, 'plain');
    $results['plain_batch'] = $metrics["batch plain chunk=$chunkSz [plain]"]['elapsed_ms'];

    if (checkEncryptSupport()) {
        // Encrypt single
        $dbES = new JsonDatabase('enc_single', $dir);
        measure("single encrypt", function () use ($dbES, $records, $ENCRYPT_KEY) {
            return singleInsert($dbES, $records, $ENCRYPT_KEY);
        }, $N, 'enc');
        $results['enc_single'] = $metrics["single encrypt [enc]"]['elapsed_ms'];

        // Encrypt batch
        $dbEB = new JsonDatabase('enc_batch', $dir);
        measure("batch encrypt chunk=$chunkSz", function () use ($dbEB, $records, $chunkSz, $ENCRYPT_KEY) {
            return batchInsert($dbEB, $records, $chunkSz, $ENCRYPT_KEY);
        }, $N, 'enc');
        $results['enc_batch'] = $metrics["batch encrypt chunk=$chunkSz [enc]"]['elapsed_ms'];
    }

    // Tabella riepilogativa
    echo "\n";
    $w = [28, 10, 12, 10];
    $sep = '+' . implode('+', array_map(fn($v) => str_repeat('-', $v + 2), $w)) . '+';
    $row = function(array $cells) use ($w): string {
        $parts = [];
        foreach ($cells as $i => $c) $parts[] = ' ' . str_pad((string)$c, $w[$i]) . ' ';
        return '|' . implode('|', $parts) . '|';
    };

    echo "  $sep\n";
    echo "  " . $row(['Modalità', 'Tempo ms', 'ops/s', 'Speedup']) . "\n";
    echo "  $sep\n";

    $base = isset($results['plain_single']) ? $results['plain_single'] : 0;
    foreach ([
        'plain_single'  => "single / plain",
        'plain_batch'   => "batch  / plain",
        'enc_single'    => "single / encrypt",
        'enc_batch'     => "batch  / encrypt",
    ] as $key => $label) {
        if (!isset($results[$key])) continue;
        $ms      = $results[$key];
        $ops     = number_format((int)round($N / ($ms / 1000)));
        $speedup = ($base > 0) ? number_format($base / $ms, 2) . 'x' : '—';
        echo "  " . $row([$label, number_format((int)$ms), $ops, $speedup]) . "\n";
    }
    echo "  $sep\n";

    // Calcoli overhead e speedup
    if (isset($results['enc_single'], $results['plain_single'])) {
        $overhead = round(($results['enc_single'] / $results['plain_single'] - 1) * 100, 1);
        echo "\n  Overhead crittografia single: {$overhead}%\n";
    }
    if (isset($results['enc_batch'], $results['plain_batch'])) {
        $overhead = round(($results['enc_batch'] / $results['plain_batch'] - 1) * 100, 1);
        echo "  Overhead crittografia batch:  {$overhead}%\n";
    }
    if (isset($results['plain_batch'], $results['plain_single'])) {
        $speedup = round($results['plain_single'] / $results['plain_batch'], 2);
        echo "  Speedup batch vs single (plain):   {$speedup}x\n";
    }
    if (isset($results['enc_batch'], $results['enc_single'])) {
        $speedup = round($results['enc_single'] / $results['enc_batch'], 2);
        echo "  Speedup batch vs single (encrypt): {$speedup}x\n";
    }

    deleteDir($dir);
}

// ===========================================================================
// SUITE: READ
// ===========================================================================
if (in_array($suite, ['all', 'read'])) {

    $runRead = function(?string $encKey, string $tag) use ($N, $chunkSz) {
        section("READ STRESS (N=$N, mode=$tag)");

        $dir     = freshDir("read_$tag");
        $db      = new JsonDatabase('users', $dir);
        $records = DataGenerator::makeBatch($N);

        // Popola con la strategia batch (più veloce, non è il soggetto del test)
        $ids = batchInsert($db, $records, $chunkSz, $encKey);

        // selectById – campione casuale 100 record
        $sample = array_intersect_key($ids, array_flip((array)array_rand($ids, min(100, count($ids)))));
        measure("select_by_id_100x", function () use ($db, $sample, $encKey) {
            foreach ($sample as $id) {
                $rec = $db->selectById($id);
                if ($rec && $encKey) decryptRecord($rec, $encKey);
            }
        }, count($sample), $tag);

        // selectAll + decrypt
        $all = measure("select_all_{$N}", function () use ($db, $encKey) {
            $recs = $db->selectAll();
            if ($encKey && $recs) {
                $recs = array_map(fn($r) => decryptRecord($r, $encKey), $recs);
            }
            return $recs;
        }, $N, $tag);
        echo "  → selectAll: " . count((array)$all) . " record\n";

        // selectWhere low selectivity
        measure("select_where_low_sel", fn() => $db->selectWhere(['country' => 'IT']), $N, $tag);

        // selectWhere high selectivity — usa il valore cifrato se in modalità encrypt
        $firstRec   = $db->selectById($ids[0]);
        $targetMail = $firstRec['email'];  // campo potenzialmente cifrato
        $found = measure("select_where_high_sel",
            fn() => $db->selectWhere(['email' => $targetMail]), $N, $tag);
        echo "  → selectWhere email: " . count($found ?? []) . " risultato/i\n";

        // getStats
        $stats = measure("get_stats_{$N}", fn() => $db->getStats(), 1, $tag);
        echo "  → frag: {$stats['fragmentation_percent']}% | lines: {$stats['total_lines']}\n";

        deleteDir($dir);
    };

    runModes($mode, $ENCRYPT_KEY, $runRead);
}

// ===========================================================================
// SUITE: WRITE (update + delete)
// ===========================================================================
if (in_array($suite, ['all', 'write'])) {

    $runWrite = function(?string $encKey, string $tag) use ($N, $chunkSz) {
        section("WRITE STRESS – UPDATE + DELETE (N=$N, mode=$tag)");

        $dir     = freshDir("write_$tag");
        $db      = new JsonDatabase('users', $dir);
        $records = DataGenerator::makeBatch($N);
        $ids     = batchInsert($db, $records, $chunkSz, $encKey);

        // Update tutti
        measure("update_all_{$N}", function () use ($db, $ids, $encKey) {
            foreach ($ids as $id) {
                $upd = ['score' => mt_rand(0, 10000) / 100, 'updated' => true];
                if ($encKey) $upd = encryptRecord($upd, $encKey);
                $db->update($id, $upd);
            }
        }, $N, $tag);

        $sz = round(filesize("$dir/users.jsonl.php") / 1024, 1);
        echo "  → data file dopo update: {$sz} KB\n";

        // Soft delete 50%
        $toDelete = array_slice($ids, 0, (int)($N / 2));
        measure("delete_{$N}half", function () use ($db, $toDelete) {
            foreach ($toDelete as $id) $db->delete($id);
        }, count($toDelete), $tag);

        $stats = $db->getStats();
        echo "  → frag: {$stats['fragmentation_percent']}% | active: {$stats['active_records']}\n";

        deleteDir($dir);
    };

    runModes($mode, $ENCRYPT_KEY, $runWrite);
}

// ===========================================================================
// SUITE: COMPACT + REBUILD
// ===========================================================================
if (in_array($suite, ['all', 'compact'])) {

    $runCompact = function(?string $encKey, string $tag) use ($N, $chunkSz) {
        section("COMPACTION + REBUILD STRESS (N=$N, mode=$tag)");

        $dir     = freshDir("compact_$tag");
        $db      = new JsonDatabase('users', $dir);
        $records = DataGenerator::makeBatch($N);
        $ids     = batchInsert($db, $records, $chunkSz, $encKey);

        // Update tutti + delete 30%
        foreach ($ids as $id) {
            $upd = ['score' => mt_rand(0, 100)];
            if ($encKey) $upd = encryptRecord($upd, $encKey);
            $db->update($id, $upd);
        }
        $toDelete = array_slice($ids, 0, (int)($N * 0.3));
        foreach ($toDelete as $id) $db->delete($id);

        $statsBefore = $db->getStats();
        $szBefore    = round($statsBefore['data_file_size'] / 1024, 1);

        $compResult = measure("compact_after_frag_{$N}",
            fn() => $db->compact(), $statsBefore['active_records'], $tag);

        $statsAfter = $db->getStats();
        $szAfter    = round($statsAfter['data_file_size'] / 1024, 1);
        $saved      = round(($compResult['space_saved'] ?? 0) / 1024, 1);
        echo "  → pre: {$szBefore} KB ({$statsBefore['fragmentation_percent']}% frag)"
           . " | post: {$szAfter} KB | recuperato: {$saved} KB\n";

        // rebuildIndex dopo cancellazione manuale
        $indexPath = "$dir/users.index.php";
        unlink($indexPath);
        measure("rebuild_index_{$N}", fn() => $db->rebuildIndex(),
            $statsAfter['active_records'], $tag);
        echo "  → count dopo rebuild: " . $db->count() . "\n";

        deleteDir($dir);
    };

    runModes($mode, $ENCRYPT_KEY, $runCompact);
}

// ===========================================================================
// SUITE: SECONDARY INDEX
// ===========================================================================
if (in_array($suite, ['all', 'secondary'])) {

    $runSecondary = function(?string $encKey, string $tag) use ($N, $chunkSz) {
        section("SECONDARY INDEX STRESS (N=$N, mode=$tag)");

        $dir   = freshDir("secondary_$tag");
        $db    = new JsonDatabase('sidx', $dir, ['score', 'tier']);
        $tiers = ['bronze', 'gold', 'silver'];

        // 1. Insert con indici secondari — usa insertBatch per velocità
        $insertedIds = [];
        measure("sidx_insert_{$N}", function () use ($db, $N, $tiers, $chunkSz, $encKey, &$insertedIds) {
            $batch = [];
            for ($i = 0; $i < $N; $i++) {
                $rec = [
                    'name'  => 'User' . $i,
                    'score' => $i % 10001,
                    'tier'  => $tiers[$i % 3],
                    'extra' => str_repeat('x', 32),
                ];
                // Nota: in modalità encrypt cifriamo solo 'name', non score/tier
                // perché i secondary index lavorano sui valori in chiaro.
                if ($encKey) $rec = encryptRecord(['name' => $rec['name']], $encKey) + $rec;
                $batch[] = $rec;
                if (count($batch) === $chunkSz) {
                    $res = $db->insertBatch($batch);
                    if ($res) $insertedIds = array_merge($insertedIds, $res['ids']);
                    $batch = [];
                }
            }
            if ($batch) {
                $res = $db->insertBatch($batch);
                if ($res) $insertedIds = array_merge($insertedIds, $res['ids']);
            }
        }, $N, $tag);

        echo "  → inseriti: " . count($insertedIds) . " record con 2 indici secondari\n";
        echo "  → dirty dopo insert: "
            . ($db->getStats()['secondary_indexes']['score']['dirty'] ? 'true' : 'false') . "\n";

        // 2. Rebuild esplicito
        measure("sidx_rebuild_explicit", fn() => $db->rebuildSecondaryIndexes(), $N, $tag);

        $stats     = $db->getStats();
        $scoreSzKB = round($stats['secondary_indexes']['score']['file_size'] / 1024, 1);
        $tierSzKB  = round($stats['secondary_indexes']['tier']['file_size']  / 1024, 1);
        echo "  → score dirty dopo rebuild: "
            . ($stats['secondary_indexes']['score']['dirty'] ? 'true' : 'false') . "\n";
        echo "  → file indice: score={$scoreSzKB} KB | tier={$tierSzKB} KB\n";

        // 3. Range stretto int (≈1%)
        $narrowResult = measure("sidx_range_int_narrow",
            fn() => $db->selectRange('score', 0, 100), $N, $tag);
        echo "  → score [0,100]: " . count((array)$narrowResult)
            . " (atteso ~" . (int)($N * 101 / 10001) . ")\n";

        // 4. Range ampio int (≈50%)
        $wideResult = measure("sidx_range_int_wide",
            fn() => $db->selectRange('score', 0, 5000), $N, $tag);
        echo "  → score [0,5000]: " . count((array)$wideResult)
            . " (atteso ~" . (int)($N * 5001 / 10001) . ")\n";

        // 5. Range stringa
        $tierResult = measure("sidx_range_string",
            fn() => $db->selectRange('tier', 'bronze', 'gold'), $N, $tag);
        echo "  → tier [bronze,gold]: " . count((array)$tierResult)
            . " (atteso ~" . (int)($N * 2 / 3) . ")\n";

        // 6. Range + condizione
        $condResult = measure("sidx_range_with_condition",
            fn() => $db->selectRange('score', 0, 2000, ['tier' => 'gold']), $N, $tag);
        echo "  → score [0,2000] AND tier=gold: " . count((array)$condResult) . " record\n";

        // 7. Range con limit
        $limitN = (int)($N / 10);
        measure("sidx_range_with_limit",
            fn() => $db->selectRange('score', 0, 10000, [], $limitN), $limitN, $tag);
        echo "  → range con limit={$limitN}\n";

        // 8. Lazy rebuild dopo update 10%
        $toUpdate = array_slice($insertedIds, 0, (int)($N * 0.1));
        foreach ($toUpdate as $id) {
            $db->update($id, [
                'name'  => 'Updated' . $id,
                'score' => mt_rand(0, 10000),
                'tier'  => $tiers[mt_rand(0, 2)],
                'extra' => str_repeat('y', 32),
            ]);
        }
        echo "  → dirty dopo " . count($toUpdate) . " update: "
            . ($db->getStats()['secondary_indexes']['score']['dirty'] ? 'true' : 'false') . "\n";

        $lazyResult = measure("sidx_lazy_rebuild_after_write",
            fn() => $db->selectRange('score', 5000, 7500), $N, $tag);
        echo "  → score [5000,7500] post-lazy-rebuild: " . count((array)$lazyResult) . " record\n";

        // 9. 10 query consecutive (indice clean)
        measure("sidx_consecutive_10x", function () use ($db) {
            for ($i = 0; $i < 10; $i++) {
                $lo = mt_rand(0, 5000);
                $db->selectRange('score', $lo, $lo + 2000);
            }
        }, 10, $tag);

        // 10. Rebuild esplicito isolato
        $db->update($insertedIds[0], ['name' => 'Trigger', 'score' => 1, 'tier' => 'bronze', 'extra' => 'z']);
        measure("sidx_rebuild_explicit_isolated",
            fn() => $db->rebuildSecondaryIndexes(), $N, $tag);

        // 11. Compact + query post-compact
        $toDelete = array_slice($insertedIds, (int)($N * 0.1), (int)($N * 0.2));
        foreach ($toDelete as $id) $db->delete($id);

        $compactResult = measure("sidx_compact_{$N}",
            fn() => $db->compact(), (int)($N * 0.8), $tag);

        $statsAfterCompact = $db->getStats();
        $szAfterKB = round($statsAfterCompact['data_file_size'] / 1024, 1);
        echo "  → compact: kept={$compactResult['records_kept']}"
            . " deleted={$compactResult['deleted_records']}"
            . " obsolete={$compactResult['obsolete_versions']}"
            . " | data={$szAfterKB} KB\n";

        $postResult = measure("sidx_range_post_compact",
            fn() => $db->selectRange('score', 0, 10000),
            $statsAfterCompact['active_records'], $tag);
        echo "  → score [0,10000] post-compact: " . count((array)$postResult)
            . " (active: {$statsAfterCompact['active_records']})\n";

        deleteDir($dir);
    };

    runModes($mode, $ENCRYPT_KEY, $runSecondary);
}

// ===========================================================================
// REPORT FINALE
// ===========================================================================
if (!empty($metrics)) {
    $w   = [42, 5, 10, 10, 10, 8, 12];
    $sep = '+' . implode('+', array_map(fn($v) => str_repeat('-', $v + 2), $w)) . '+';
    $row = function(array $cells) use ($w): string {
        $parts = [];
        foreach ($cells as $i => $c) {
            $parts[] = ' ' . str_pad(mb_substr((string)$c, 0, $w[$i]), $w[$i]) . ' ';
        }
        return '|' . implode('|', $parts) . '|';
    };

    echo "\n" . $sep . "\n";
    echo $row(['Label', 'Mode', 'Elapsed ms', 'ops/s', 'ΔMem KB', 'Peak MB', 'Avg µs/op']) . "\n";
    echo $sep . "\n";

    foreach ($metrics as $m) {
        $thr = isset($m['throughput']) ? number_format((int)$m['throughput']) : '—';
        $dm  = ($m['mem_delta_kb'] >= 0 ? '+' : '') . number_format((int)$m['mem_delta_kb']);

        // Calcolo del tempo medio per operazione in microsecondi
        $avgUs = '—';
        if (isset($m['throughput']) && $m['throughput'] > 0) {
            $avgUs = number_format(1_000_000 / $m['throughput'], 0);
        }

        echo $row([
            $m['label'],
            $m['tag'] ?? '',
            number_format((int)($m['elapsed_ms'] ?? 0)),
            $thr,
            $dm,
            number_format($m['peak_mem_mb'] ?? 0, 2),
            $avgUs,
        ]) . "\n";
    }

    echo $sep . "\n";
}

// Info di sistema
echo "\nParametri:\n";
echo "  N=$N  mode=$mode  insert=$insertArg  chunk=$chunkSz\n";
echo "  PHP " . PHP_VERSION . "  memory_limit=" . ini_get('memory_limit') . "\n";
if (PHP_OS_FAMILY !== 'Windows') {
    $vminfo = shell_exec('cat /proc/self/status 2>/dev/null | grep -E "VmRSS|VmPeak|VmSize"');
    if ($vminfo) echo "\nRSS/VSZ:\n" . $vminfo;
}
echo "\n";
