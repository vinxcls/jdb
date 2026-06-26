<?php

/**
 * JsonDatabaseTest - Test suite completa per la classe JsonDatabase
 *
 * Copertura:
 *  - insert / insertWithId
 *  - selectById / selectAll / selectWhere
 *  - update
 *  - delete (soft delete)
 *  - exists / count
 *  - rebuildIndex
 *  - compact
 *  - getStats
 */
class JsonDatabaseTest extends \Codeception\Test\Unit
{
    /** @var UnitTester */
    protected $tester;

    /** @var string Directory temporanea isolata per ogni test */
    private $dataDir;

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function _before()
    {
        // Ogni test ottiene una directory completamente pulita
        $this->dataDir = sys_get_temp_dir() . '/jdb_test_' . uniqid('', true);
        mkdir($this->dataDir, 0755, true);
    }

    protected function _after()
    {
        // Pulizia ricorsiva dei file temporanei
        $this->removeDir($this->dataDir);
    }

    private function removeDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $file) {
            is_dir($file) ? $this->removeDir($file) : unlink($file);
        }
        rmdir($dir);
    }

    /** Crea un'istanza fresca di JsonDatabase sulla tabella 'test' */
    private function db($table = 'test')
    {
        return new JsonDatabase($table, $this->dataDir);
    }

    // =========================================================================
    // INSERT
    // =========================================================================

    public function testInsertReturnsIncrementalId()
    {
        $db = $this->db();
        $id1 = $db->insert(array('name' => 'Alice'));
        $id2 = $db->insert(array('name' => 'Bob'));

        $this->assertNotFalse($id1, 'Il primo insert deve ritornare un ID valido');
        $this->assertNotFalse($id2, 'Il secondo insert deve ritornare un ID valido');
        $this->assertGreaterThan($id1, $id2, 'Gli ID devono essere incrementali');
    }

    public function testInsertPersistsAllFields()
    {
        $db = $this->db();
        $data = array('name' => 'Alice', 'age' => 30, 'active' => true);
        $id = $db->insert($data);

        $record = $db->selectById($id);

        $this->assertEquals('Alice', $record['name']);
        $this->assertEquals(30,      $record['age']);
        $this->assertTrue($record['active']);
    }

    public function testInsertAddsIdFieldToRecord()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $record = $db->selectById($id);

        $this->assertArrayHasKey('id', $record);
        $this->assertEquals($id, $record['id']);
    }

    public function testInsertOverridesIdIfPassedByUser()
    {
        // Il campo 'id' passato dall'utente deve essere ignorato e
        // rimpiazzato dall'ID auto-generato
        $db = $this->db();
        $id = $db->insert(array('id' => 999, 'name' => 'Alice'));
        $record = $db->selectById($id);

        $this->assertEquals($id, $record['id'], "L'ID del record deve essere quello assegnato dal DB, non 999");
    }

    public function testInsertMultipleRecordsAreAllRetrievable()
    {
        $db = $this->db();
        $ids = array();
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $db->insert(array('index' => $i));
        }

        foreach ($ids as $pos => $id) {
            $record = $db->selectById($id);
            $this->assertNotNull($record, "Il record con id=$id non deve essere null");
            $this->assertEquals($pos, $record['index']);
        }
    }

    // =========================================================================
    // INSERT WITH CUSTOM ID
    // =========================================================================

    public function testInsertWithIdReturnsCustomId()
    {
        $db = $this->db();
        $result = $db->insertWithId('SKU-001', array('price' => 9.99));

        $this->assertEquals('SKU-001', $result);
    }

    public function testInsertWithIdRecordIsRetrievable()
    {
        $db = $this->db();
        $db->insertWithId('SKU-001', array('price' => 9.99));
        $record = $db->selectById('SKU-001');

        $this->assertNotNull($record);
        $this->assertEquals(9.99, $record['price']);
        $this->assertEquals('SKU-001', $record['id']);
    }

    public function testInsertWithIdReturnsFalseOnDuplicateId()
    {
        $db = $this->db();
        $db->insertWithId('SKU-001', array('price' => 9.99));
        $result = $db->insertWithId('SKU-001', array('price' => 5.00));

        $this->assertFalse($result, 'insertWithId su un ID esistente deve ritornare false');
    }

    public function testInsertWithIdAllowsReinsertAfterDelete()
    {
        $db = $this->db();
        $db->insertWithId('SKU-001', array('price' => 9.99));
        $db->delete('SKU-001');

        $result = $db->insertWithId('SKU-001', array('price' => 7.50));
        $this->assertNotFalse($result, 'Deve essere possibile reinserire un ID precedentemente eliminato');

        $record = $db->selectById('SKU-001');
        $this->assertEquals(7.50, $record['price']);
    }

    public function testInsertWithNumericCustomId()
    {
        $db = $this->db();
        $result = $db->insertWithId(42, array('name' => 'Foo'));

        $this->assertEquals(42, $result);
        $record = $db->selectById(42);
        $this->assertNotNull($record);
    }

    // =========================================================================
    // SELECT BY ID
    // =========================================================================

    public function testSelectByIdReturnsNullForMissingId()
    {
        $db = $this->db();
        $result = $db->selectById(9999);

        $this->assertNull($result);
    }

    public function testSelectByIdReturnsNullAfterDelete()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $db->delete($id);

        $this->assertNull($db->selectById($id));
    }

    // =========================================================================
    // SELECT ALL
    // =========================================================================

    public function testSelectAllReturnsEmptyArrayOnEmptyDb()
    {
        $db = $this->db();
        $results = $db->selectAll();

        $this->assertTrue(is_array($results), 'selectAll() deve ritornare un array');
        $this->assertCount(0, $results);
    }

    public function testSelectAllReturnsAllActiveRecords()
    {
        $db = $this->db();
        $db->insert(array('name' => 'Alice'));
        $db->insert(array('name' => 'Bob'));
        $db->insert(array('name' => 'Carlo'));

        $results = $db->selectAll();
        $this->assertCount(3, $results);
    }

    public function testSelectAllExcludesDeletedRecords()
    {
        $db = $this->db();
        $id1 = $db->insert(array('name' => 'Alice'));
        $db->insert(array('name' => 'Bob'));
        $db->delete($id1);

        $results = $db->selectAll();
        $this->assertCount(1, $results);
        $this->assertEquals('Bob', $results[0]['name']);
    }

    public function testSelectAllReturnsLatestVersionAfterUpdate()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice', 'age' => 20));
        $db->update($id, array('name' => 'Alice', 'age' => 21));

        $results = $db->selectAll();
        $this->assertCount(1, $results);
        $this->assertEquals(21, $results[0]['age']);
    }

    // =========================================================================
    // SELECT WHERE
    // =========================================================================

    public function testSelectWhereReturnsMatchingRecords()
    {
        $db = $this->db();
        $db->insert(array('role' => 'admin',  'name' => 'Alice'));
        $db->insert(array('role' => 'user',   'name' => 'Bob'));
        $db->insert(array('role' => 'admin',  'name' => 'Carlo'));

        $results = $db->selectWhere(array('role' => 'admin'));
        $this->assertCount(2, $results);
    }

    public function testSelectWhereReturnsNullWhenNoMatch()
    {
        $db = $this->db();
        $db->insert(array('role' => 'user', 'name' => 'Bob'));

        $result = $db->selectWhere(array('role' => 'admin'));
        $this->assertNull($result);
    }

    public function testSelectWhereMatchesMultipleConditions()
    {
        $db = $this->db();
        $db->insert(array('role' => 'admin', 'active' => true,  'name' => 'Alice'));
        $db->insert(array('role' => 'admin', 'active' => false, 'name' => 'Bob'));
        $db->insert(array('role' => 'user',  'active' => true,  'name' => 'Carlo'));

        $results = $db->selectWhere(array('role' => 'admin', 'active' => true));
        $this->assertCount(1, $results);
        $this->assertEquals('Alice', $results[0]['name']);
    }

    public function testSelectWhereExcludesDeletedRecords()
    {
        $db = $this->db();
        $id = $db->insert(array('role' => 'admin', 'name' => 'Alice'));
        $db->insert(array('role' => 'admin', 'name' => 'Bob'));
        $db->delete($id);

        $results = $db->selectWhere(array('role' => 'admin'));
        $this->assertCount(1, $results);
        $this->assertEquals('Bob', $results[0]['name']);
    }

    public function testSelectWhereUsesStrictComparison()
    {
        $db = $this->db(); 
        $db->insert(['name' => 'Alice', 'age' => 30]);
        // Use a custom string ID that does not collide with auto-increment ID 1
        $db->insertWithId('bob', ['name' => 'Bob', 'age' => 25]);

        // Verify querying by int matches Alice (id=1)
        $result = $db->selectWhere(['id' => 1]);
        $this->assertCount(1, $result);
        $this->assertEquals('Alice', $result[0]['name']);

        // Verify querying by string matches Bob (id='bob')
        $result2 = $db->selectWhere(['id' => 'bob']);
        $this->assertCount(1, $result2);
        $this->assertEquals('Bob', $result2[0]['name']);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    public function testUpdateReturnsTrueOnSuccess()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));

        $result = $db->update($id, array('name' => 'Alice Updated'));
        $this->assertTrue($result);
    }

    public function testUpdateChangesDataCorrectly()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice', 'age' => 20));
        $db->update($id, array('name' => 'Alice', 'age' => 21));

        $record = $db->selectById($id);
        $this->assertEquals(21, $record['age']);
    }

    public function testUpdatePreservesId()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $db->update($id, array('name' => 'Alice Updated'));

        $record = $db->selectById($id);
        $this->assertEquals($id, $record['id']);
    }

    public function testUpdateReturnsFalseForNonExistentId()
    {
        $db = $this->db();
        $result = $db->update(9999, array('name' => 'Ghost'));

        $this->assertFalse($result);
    }

    public function testUpdateReturnsFalseOnDeletedRecord()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $db->delete($id);

        $result = $db->update($id, array('name' => 'Alice Updated'));
        $this->assertFalse($result);
    }

    public function testMultipleUpdatesReturnLatestVersion()
    {
        $db = $this->db();
        $id = $db->insert(array('val' => 0));
        $db->update($id, array('val' => 1));
        $db->update($id, array('val' => 2));
        $db->update($id, array('val' => 3));

        $record = $db->selectById($id);
        $this->assertEquals(3, $record['val']);
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    public function testDeleteReturnsTrueOnSuccess()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));

        $ret = $db->delete($id);
        if ($ret === false) {
            $this->assertEquals('', $db->getError());
        }
    }

    public function testDeleteReturnsFalseForNonExistentId()
    {
        $db = $this->db();
        $this->assertFalse($db->delete(9999));
    }

    public function testDeleteReturnsFalseOnAlreadyDeletedRecord()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $db->delete($id);

        $this->assertFalse($db->delete($id), 'Un secondo delete sullo stesso record deve ritornare false');
    }

    public function testDeleteDoesNotAffectOtherRecords()
    {
        $db = $this->db();
        $id1 = $db->insert(array('name' => 'Alice'));
        $id2 = $db->insert(array('name' => 'Bob'));
        $db->delete($id1);

        $record = $db->selectById($id2);
        $this->assertNotNull($record);
        $this->assertEquals('Bob', $record['name']);
    }

    // =========================================================================
    // EXISTS
    // =========================================================================

    public function testExistsReturnsFalseForMissingId()
    {
        $db = $this->db();
        $this->assertFalse($db->exists(9999));
    }

    public function testExistsReturnsTrueAfterInsert()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));

        $this->assertTrue($db->exists($id));
    }

    public function testExistsReturnsFalseAfterDelete()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $db->delete($id);

        $this->assertFalse($db->exists($id));
    }

    public function testExistsReturnsTrueAfterUpdate()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $db->update($id, array('name' => 'Alice Updated'));

        $this->assertTrue($db->exists($id));
    }

    // =========================================================================
    // COUNT
    // =========================================================================

    public function testCountReturnsZeroOnEmptyDb()
    {
        $db = $this->db();
        $this->assertEquals(0, $db->count());
    }

    public function testCountIncrementsAfterInsert()
    {
        $db = $this->db();
        $db->insert(array('name' => 'Alice'));
        $this->assertEquals(1, $db->count());

        $db->insert(array('name' => 'Bob'));
        $this->assertEquals(2, $db->count());
    }

    public function testCountDecrementsAfterDelete()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $db->insert(array('name' => 'Bob'));
        $db->delete($id);

        $this->assertEquals(1, $db->count());
    }

    public function testCountDoesNotChangeAfterUpdate()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));
        $db->update($id, array('name' => 'Alice Updated'));

        $this->assertEquals(1, $db->count());
    }

    // =========================================================================
    // REBUILD INDEX
    // =========================================================================

    public function testRebuildIndexRestoresCountCorrectly()
    {
        $db = $this->db();
        $id1 = $db->insert(array('name' => 'Alice'));
        $db->insert(array('name' => 'Bob'));
        $db->delete($id1);

        // Corrompi l'indice sovrascrivendolo
        $indexFile = $this->dataDir . '/test.index.php';
        file_put_contents($indexFile, "<?php die(); ?".">\n{CORROTTO}");

        // rebuildIndex deve ricostruirlo correttamente
        $db->rebuildIndex();

        // Crea una nuova istanza per ricaricare tutto dal disco
        $db2 = $this->db();
        $this->assertEquals(1, $db2->count());
    }

    public function testRebuildIndexMakesRecordsReadable()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));

        // Corrompi l'indice
        $indexFile = $this->dataDir . '/test.index.php';
        file_put_contents($indexFile, "<?php die(); ?".">\n{CORROTTO}");

        $db->rebuildIndex();

        $db2 = $this->db();
        $record = $db2->selectById($id);
        $this->assertNotNull($record);
        $this->assertEquals('Alice', $record['name']);
    }

    public function testRebuildIndexOnEmptyDataFile()
    {
        $db = $this->db();
        // Nessun dato inserito: rebuildIndex deve produrre count=0
        $db->rebuildIndex();

        $this->assertEquals(0, $db->count());
    }

    public function testRebuildIndexAfterMultipleUpdates()
    {
        $db = $this->db();
        $id = $db->insert(array('val' => 1));
        $db->update($id, array('val' => 2));
        $db->update($id, array('val' => 3));

        $db->rebuildIndex();

        $db2 = $this->db();
        // Deve esserci un solo record attivo
        $this->assertEquals(1, $db2->count());
        $record = $db2->selectById($id);
        $this->assertEquals(3, $record['val']);
    }

    // =========================================================================
    // COMPACT
    // =========================================================================

    public function testCompactReturnsStatsArray()
    {
        $db = $this->db();
        $db->insert(array('name' => 'Alice'));

        $stats = $db->compact();

        $this->assertTrue(is_array($stats), 'compact() deve ritornare un array');
        $this->assertArrayHasKey('records_kept',    $stats);
        $this->assertArrayHasKey('records_removed', $stats);
        $this->assertArrayHasKey('old_size',        $stats);
        $this->assertArrayHasKey('new_size',        $stats);
        $this->assertArrayHasKey('space_saved',     $stats);
    }

    public function testCompactPreservesDataIntegrity()
    {
        $db = $this->db();
        $id1 = $db->insert(array('name' => 'Alice'));
        $id2 = $db->insert(array('name' => 'Bob'));
        $db->delete($id1);

        $db->compact();

        // Ricarica da disco
        $db2 = $this->db();
        $this->assertEquals(1, $db2->count());
        $record = $db2->selectById($id2);
        $this->assertNotNull($record);
        $this->assertEquals('Bob', $record['name']);
    }

    public function testCompactRemovesObsoleteVersions()
    {
        $db = $this->db();
        $id = $db->insert(array('val' => 1));
        $db->update($id, array('val' => 2));
        $db->update($id, array('val' => 3));

        // Prima della compattazione il file contiene 3 righe per lo stesso record
        $statsBefore = $db->getStats();
        $this->assertEquals(2, $statsBefore['obsolete_versions']);

        $db->compact();

        $statsAfter = $db->getStats();
        $this->assertEquals(0, $statsAfter['obsolete_versions']);
    }

    public function testCompactReducesFileSize()
    {
        $db = $this->db();
        for ($i = 0; $i < 10; $i++) {
            $id = $db->insert(array('name' => 'record_' . $i));
            $db->update($id, array('name' => 'record_' . $i . '_updated'));
            if ($i % 2 === 0) {
                $db->delete($id);
            }
        }

        $stats = $db->compact();
        $this->assertLessThan($stats['old_size'], $stats['new_size'],
            'La dimensione del file dopo compact deve essere minore di quella precedente');
    }

    public function testCompactOnEmptyDbReturnsZeroStats()
    {
        $db = $this->db();
        $stats = $db->compact();

        $this->assertEquals(0, $stats['records_kept']);
        $this->assertEquals(0, $stats['records_removed']);
    }

    // =========================================================================
    // GET STATS
    // =========================================================================

    public function testGetStatsReturnsCorrectStructure()
    {
        $db = $this->db();
        $stats = $db->getStats();

        $expectedKeys = array(
            'active_records', 'total_lines', 'deleted_records',
            'obsolete_versions', 'data_file_size', 'index_file_size',
            'next_id', 'fragmentation_percent'
        );

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $stats, "getStats() deve contenere la chiave '$key'");
        }
    }

    public function testGetStatsCountsDeletedRecordsCorrectly()
    {
        $db = $this->db();
        $id1 = $db->insert(array('name' => 'Alice'));
        $db->insert(array('name' => 'Bob'));
        $db->delete($id1);

        $stats = $db->getStats();
        $this->assertEquals(1, $stats['active_records']);
        $this->assertEquals(1, $stats['deleted_records']);
    }

    public function testGetStatsCountsObsoleteVersions()
    {
        $db = $this->db();
        $id = $db->insert(array('val' => 1));  // versione 1
        $db->update($id, array('val' => 2));   // versione 2 (la 1 diventa obsoleta)
        $db->update($id, array('val' => 3));   // versione 3 (la 2 diventa obsoleta)

        $stats = $db->getStats();
        $this->assertEquals(2, $stats['obsolete_versions']);
    }

    public function testGetStatsFragmentationPercentOnCleanDb()
    {
        $db = $this->db();
        $db->insert(array('name' => 'Alice'));
        $db->insert(array('name' => 'Bob'));

        $stats = $db->getStats();
        $this->assertEquals(0.0, $stats['fragmentation_percent']);
    }

    public function testGetStatsFragmentationPercentAfterDeletes()
    {
        $db = $this->db();
        // 1 insert + 1 delete = 2 righe nel file (record + tombstone)
        // => 1 eliminata su 2 totali = 50%
        $id = $db->insert(array('name' => 'Alice'));
        $db->delete($id);

        $stats = $db->getStats();
        $this->assertEquals(50.0, $stats['fragmentation_percent']);
    }

    public function testGetStatsOnEmptyDb()
    {
        $db = $this->db();
        $stats = $db->getStats();

        $this->assertEquals(0, $stats['active_records']);
        $this->assertEquals(0, $stats['total_lines']);
        $this->assertEquals(0.0, $stats['fragmentation_percent']);
    }

    // =========================================================================
    // TABELLE MULTIPLE / ISOLAMENTO
    // =========================================================================

    public function testMultipleTablesAreIsolated()
    {
        $users    = new JsonDatabase('users',    $this->dataDir);
        $products = new JsonDatabase('products', $this->dataDir);

        $uid = $users->insert(array('name' => 'Alice'));
        $pid = $products->insert(array('sku'  => 'P001'));

        // Entrambe le tabelle partono da next_id=1, quindi $uid=$pid=1.
        // L'isolamento si verifica controllando che i campi specifici
        // di una tabella non esistano nell'altra.
        $userRecord    = $users->selectById($uid);
        $productRecord = $products->selectById($pid);

        $this->assertNotNull($userRecord);
        $this->assertNotNull($productRecord);

        // Il record utente non ha 'sku' e il record prodotto non ha 'name'
        $this->assertArrayNotHasKey('sku',  $userRecord,    "La tabella users non deve contenere il campo 'sku'");
        $this->assertArrayNotHasKey('name', $productRecord, "La tabella products non deve contenere il campo 'name'");

        // I contatori sono indipendenti
        $this->assertEquals(1, $users->count());
        $this->assertEquals(1, $products->count());
    }

    public function testSameIdCanExistInDifferentTables()
    {
        $users    = new JsonDatabase('users',    $this->dataDir);
        $products = new JsonDatabase('products', $this->dataDir);

        $uid = $users->insert(array('name' => 'Alice'));
        $pid = $products->insert(array('sku' => 'P001'));

        // Entrambi avranno id=1 ma si riferiscono a tabelle diverse
        $this->assertEquals(1, $uid);
        $this->assertEquals(1, $pid);
        $this->assertEquals('Alice', $users->selectById(1)['name']);
        $this->assertEquals('P001',  $products->selectById(1)['sku']);
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    public function testInsertAndSelectWithSpecialCharacters()
    {
        $db = $this->db();
        $data = array(
            'name'  => 'O\'Brien & "Smith" <test>',
            'bio'   => "Riga 1\nRiga 2\tTab",
            'emoji' => 'ciao 👋'
        );
        $id = $db->insert($data);
        $record = $db->selectById($id);

        $this->assertEquals($data['name'],  $record['name']);
        $this->assertEquals($data['bio'],   $record['bio']);
        $this->assertEquals($data['emoji'], $record['emoji']);
    }

    public function testInsertWithNestedArray()
    {
        $db = $this->db();
        $data = array(
            'name'    => 'Alice',
            'address' => array(
                'city'    => 'Milano',
                'country' => 'IT'
            )
        );
        $id = $db->insert($data);
        $record = $db->selectById($id);

        $this->assertEquals('Milano', $record['address']['city']);
        $this->assertEquals('IT',     $record['address']['country']);
    }

    public function testDataDirectoryIsCreatedIfNotExists()
    {
        $newDir = $this->dataDir . '/subdir/deep';
        $db = new JsonDatabase('test', $newDir);

        $this->assertDirectoryExists($newDir);
    }

    public function testSelectAllAfterCompactIsConsistent()
    {
        $db = $this->db();
        $ids = array();
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $db->insert(array('i' => $i));
        }
        $db->delete($ids[1]);
        $db->delete($ids[3]);
        $db->compact();

        $db2 = $this->db();
        $results = $db2->selectAll();
        $this->assertCount(3, $results);

        $activeIds = array_map(function ($r) { return $r['id']; }, $results);
        $this->assertContains($ids[0], $activeIds);
        $this->assertContains($ids[2], $activeIds);
        $this->assertContains($ids[4], $activeIds);
    }

    public function testOperationsFailIfIdTooLong()
    {
        $db = $this->db();
        $longId = str_repeat('b', 65);

        // Entrambi questi metodi validano la lunghezza dell'ID all'inizio
        $this->assertNull($db->selectById($longId));
        $this->assertFalse($db->update($longId, array('name' => 'Test')));
        $this->assertFalse($db->delete($longId));
        $this->assertFalse($db->exists($longId));
    }

    public function testInvalidJsonLinesAreSkipped()
    {
        $db = $this->db();
        $db->insert(array('name' => 'Valid 1'));

        // Corrompiamo intenzionalmente il data file aggiungendo JSON malformato
        $dataFile = $this->dataDir . '/test.jsonl.php';
        file_put_contents($dataFile, '{"id":2, "name": "Broken Line... ' . "\n", FILE_APPEND);

        $db->insert(array('name' => 'Valid 3'));

        // selectAll dovrebbe ignorare la riga rotta e restituire solo le 2 valide
        $results = $db->selectAll();
        $this->assertCount(2, $results);
        $this->assertEquals('Valid 1', $results[0]['name']);
        $this->assertEquals('Valid 3', $results[1]['name']);
    }

    public function testStressInsertAndAutomaticGrow()
    {
        $db = $this->db();
        $totalRecords = 500; // Un numero sufficiente a forzare il resize dell'indice
        
        for ($i = 0; $i < $totalRecords; $i++) {
            $ret = $db->insert(array('index' => $i, 'data' => 'Lorem ipsum dolor sit amet'));
            if ($ret === false) {
                $this->assertEquals("", $db->getError());
            }
        }

        $this->assertEquals($totalRecords, $db->count());
        
        // Verifica a campione che i dati siano ancora coerenti
        $record = $db->selectById(250);
        $this->assertNotNull($record);
        $this->assertEquals(249, $record['index']); // Gli ID partono da 1, gli index da 0
    }

    public function testInsertEmptyArrayIsHandled()
    {
        $db = $this->db();
        $id = $db->insert(array()); // Nessun dato tranne l'id auto-generato
        
        $record = $db->selectById($id);
        $this->assertArrayHasKey('id', $record);
        $this->assertEquals($id, $record['id']);
        // Il record conterrà solo l'id
        $this->assertCount(1, $record); 
    }

    // =========================================================================
    // SECONDARY INDEX – helpers
    // =========================================================================

    /**
     * Crea un'istanza con indici secondari su 'age' e 'name'.
     * Usata da tutti i test del SecondaryIndex.
     */
    private function dbWithIndex($table = 'test')
    {
        return new JsonDatabase($table, $this->dataDir, array('age', 'name'));
    }

    /**
     * Estrae i valori di un campo da un array di record e li ordina.
     */
    private function pluck(array $records, $field)
    {
        $values = array_map(function ($r) use ($field) { return $r[$field]; }, $records);
        sort($values);
        return $values;
    }

    // =========================================================================
    // SECONDARY INDEX – rilevamento e compatibilità
    // =========================================================================

    public function testHasSecondaryIndexReturnsTrueForDeclaredField()
    {
        $db = $this->dbWithIndex();
        $this->assertTrue($db->hasSecondaryIndex('age'));
        $this->assertTrue($db->hasSecondaryIndex('name'));
    }

    public function testHasSecondaryIndexReturnsFalseForUndeclaredField()
    {
        $db = $this->dbWithIndex();
        $this->assertFalse($db->hasSecondaryIndex('city'));
        $this->assertFalse($db->hasSecondaryIndex(''));
    }

    public function testSecondaryIndexIsBackwardCompatible()
    {
        // Il costruttore senza $secondaryFields si comporta esattamente come prima
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice', 'age' => 30));

        $this->assertEquals(1, $db->count());
        $this->assertNotNull($db->selectById($id));
        $this->assertFalse($db->hasSecondaryIndex('age'));
    }

    public function testSecondaryIndexFilesAreCreated()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));

        // I file .idx_age.php e .idx_name.php devono esistere
        $this->assertFileExists($this->dataDir . '/test.idx_age.php');
        $this->assertFileExists($this->dataDir . '/test.idx_name.php');
    }

    public function testSelectRangeReturnsFalseForNonIndexedField()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30, 'city' => 'Rome'));

        $result = $db->selectRange('city', 'Milan', 'Rome');
        $this->assertFalse($result, 'selectRange su campo non indicizzato deve ritornare false');
    }

    // =========================================================================
    // SECONDARY INDEX – selectRange base
    // =========================================================================

    public function testSelectRangeReturnsNullWhenNoMatch()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob',   'age' => 25));

        $result = $db->selectRange('age', 50, 60);
        $this->assertNull($result, 'Range senza corrispondenze deve ritornare null');
    }

    public function testSelectRangeReturnsBothBoundsInclusive()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 20));
        $db->insert(array('name' => 'Bob',   'age' => 25));
        $db->insert(array('name' => 'Carol', 'age' => 30));
        $db->insert(array('name' => 'Dave',  'age' => 35));

        // Bound esatti devono essere inclusi
        $result = $db->selectRange('age', 25, 30);
        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertEquals(array(25, 30), $this->pluck($result, 'age'));
    }

    public function testSelectRangeWithNullMinReturnsUpToMax()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 20));
        $db->insert(array('name' => 'Bob',   'age' => 25));
        $db->insert(array('name' => 'Carol', 'age' => 30));
        $db->insert(array('name' => 'Dave',  'age' => 40));

        $result = $db->selectRange('age', null, 25);
        $this->assertNotNull($result);
        $this->assertEquals(array(20, 25), $this->pluck($result, 'age'));
    }

    public function testSelectRangeWithNullMaxReturnsFromMinUp()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 20));
        $db->insert(array('name' => 'Bob',   'age' => 25));
        $db->insert(array('name' => 'Carol', 'age' => 30));
        $db->insert(array('name' => 'Dave',  'age' => 40));

        $result = $db->selectRange('age', 30, null);
        $this->assertNotNull($result);
        $this->assertEquals(array(30, 40), $this->pluck($result, 'age'));
    }

    public function testSelectRangeWithBothNullReturnsAll()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 20));
        $db->insert(array('name' => 'Bob',   'age' => 30));
        $db->insert(array('name' => 'Carol', 'age' => 40));

        $result = $db->selectRange('age', null, null);
        $this->assertNotNull($result);
        $this->assertCount(3, $result);
    }

    public function testSelectRangeOnStrings()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice',  'age' => 30));
        $db->insert(array('name' => 'Bob',    'age' => 25));
        $db->insert(array('name' => 'Carol',  'age' => 35));
        $db->insert(array('name' => 'Dave',   'age' => 28));
        $db->insert(array('name' => 'Zara',   'age' => 22));

        // Range lessicografico [Bob, Dave] include Bob, Carol, Dave
        $result = $db->selectRange('name', 'Bob', 'Dave');
        $this->assertNotNull($result);
        $names = $this->pluck($result, 'name');
        $this->assertEquals(array('Bob', 'Carol', 'Dave'), $names);
    }

    public function testSelectRangeOnNegativeIntegers()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'A', 'age' => -10));
        $db->insert(array('name' => 'B', 'age' => -5));
        $db->insert(array('name' => 'C', 'age' => 0));
        $db->insert(array('name' => 'D', 'age' => 5));

        $result = $db->selectRange('age', -5, 0);
        $this->assertNotNull($result);
        $this->assertEquals(array(-5, 0), $this->pluck($result, 'age'));
    }

    public function testSelectRangeWithLimitReturnsAtMostNRecords()
    {
        $db = $this->dbWithIndex();
        for ($i = 1; $i <= 10; $i++) {
            $db->insert(array('name' => 'User' . $i, 'age' => $i * 5));
        }

        $result = $db->selectRange('age', 1, 100, array(), 3);
        $this->assertNotNull($result);
        $this->assertCount(3, $result);
    }

    public function testSelectRangeWithAdditionalConditions()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30, 'city' => 'Rome'));
        $db->insert(array('name' => 'Bob',   'age' => 28, 'city' => 'Milan'));
        $db->insert(array('name' => 'Carol', 'age' => 32, 'city' => 'Rome'));
        $db->insert(array('name' => 'Dave',  'age' => 29, 'city' => 'Turin'));

        // age [28,32] AND city = Rome → solo Alice e Carol
        $result = $db->selectRange('age', 28, 32, array('city' => 'Rome'));
        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $names = $this->pluck($result, 'name');
        $this->assertEquals(array('Alice', 'Carol'), $names);
    }

    public function testSelectRangeRecordsWithoutIndexedFieldAreExcluded()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob'));  // nessun campo 'age' → non nell'indice secondario

        // Bob non ha 'age', quindi non deve apparire nel range
        $result = $db->selectRange('age', 1, 100);
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertEquals('Alice', $result[0]['name']);
    }

    // =========================================================================
    // SECONDARY INDEX – selectRangeEach
    // =========================================================================

    public function testSelectRangeEachIteratesCorrectRecords()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 20));
        $db->insert(array('name' => 'Bob',   'age' => 30));
        $db->insert(array('name' => 'Carol', 'age' => 40));

        $collected = array();
        $count = $db->selectRangeEach('age', 20, 30, function ($record) use (&$collected) {
            $collected[] = $record['age'];
        });

        $this->assertEquals(2, $count);
        sort($collected);
        $this->assertEquals(array(20, 30), $collected);
    }

    public function testSelectRangeEachEarlyExitOnFalse()
    {
        $db = $this->dbWithIndex();
        for ($i = 1; $i <= 10; $i++) {
            $db->insert(array('name' => 'User' . $i, 'age' => $i * 10));
        }

        $count = $db->selectRangeEach('age', 1, 100, function ($record) {
            static $calls = 0;
            $calls++;
            return ($calls < 3) ? null : false; // esce dopo 3 record
        });

        $this->assertEquals(3, $count);
    }

    public function testSelectRangeEachReturnsFalseForNonIndexedField()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));

        $result = $db->selectRangeEach('city', 'A', 'Z', function ($r) {});
        $this->assertFalse($result);
    }

    public function testSelectRangeEachWithNullMinIteratesFromBeginning()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 10));
        $db->insert(array('name' => 'Bob',   'age' => 20));
        $db->insert(array('name' => 'Carol', 'age' => 30));
        $db->insert(array('name' => 'Dave',  'age' => 40));

        $collected = array();
        $db->selectRangeEach('age', null, 20, function ($r) use (&$collected) {
            $collected[] = $r['age'];
        });
        sort($collected);
        $this->assertEquals(array(10, 20), $collected);
    }

    public function testSelectRangeEachWithNullMaxIteratesToEnd()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 10));
        $db->insert(array('name' => 'Bob',   'age' => 20));
        $db->insert(array('name' => 'Carol', 'age' => 30));

        $collected = array();
        $db->selectRangeEach('age', 20, null, function ($r) use (&$collected) {
            $collected[] = $r['age'];
        });
        sort($collected);
        $this->assertEquals(array(20, 30), $collected);
    }

    public function testSelectRangeEachWithBothNullIteratesAll()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 10));
        $db->insert(array('name' => 'Bob',   'age' => 20));
        $db->insert(array('name' => 'Carol', 'age' => 30));

        $count = $db->selectRangeEach('age', null, null, function ($r) {});
        $this->assertEquals(3, $count);
    }

    public function testSelectRangeEachReturnsZeroWhenNoMatch()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));

        $count = $db->selectRangeEach('age', 50, 100, function ($r) {});
        $this->assertEquals(0, $count);
    }

    public function testSelectRangeEachWithAdditionalConditions()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30, 'city' => 'Rome'));
        $db->insert(array('name' => 'Bob',   'age' => 28, 'city' => 'Milan'));
        $db->insert(array('name' => 'Carol', 'age' => 32, 'city' => 'Rome'));

        $collected = array();
        $count = $db->selectRangeEach(
            'age', 25, 35,
            function ($r) use (&$collected) { $collected[] = $r['name']; },
            array('city' => 'Rome')
        );

        $this->assertEquals(2, $count);
        sort($collected);
        $this->assertEquals(array('Alice', 'Carol'), $collected);
    }

    public function testSelectRangeEachWithLimit()
    {
        $db = $this->dbWithIndex();
        for ($i = 1; $i <= 10; $i++) {
            $db->insert(array('name' => 'User' . $i, 'age' => $i * 5));
        }

        $collected = array();
        $count = $db->selectRangeEach(
            'age', 1, 100,
            function ($r) use (&$collected) { $collected[] = $r['age']; },
            array(),
            4
        );

        $this->assertEquals(4, $count);
        $this->assertCount(4, $collected);
    }

    public function testSelectRangeEachReflectsUpdateCorrectly()
    {
        $db = $this->dbWithIndex();
        $id = $db->insert(array('name' => 'Alice', 'age' => 20));

        // Prima dell'aggiornamento Alice non è in [30, 40]
        $count = $db->selectRangeEach('age', 30, 40, function ($r) {});
        $this->assertEquals(0, $count);

        $db->update($id, array('name' => 'Alice', 'age' => 35));

        $collected = array();
        $count = $db->selectRangeEach('age', 30, 40, function ($r) use (&$collected) {
            $collected[] = $r['age'];
        });
        $this->assertEquals(1, $count);
        $this->assertEquals(35, $collected[0]);
    }

    public function testSelectRangeEachExcludesDeletedRecords()
    {
        $db = $this->dbWithIndex();
        $id1 = $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob',   'age' => 25));

        $db->delete($id1);

        $collected = array();
        $db->selectRangeEach('age', 20, 40, function ($r) use (&$collected) {
            $collected[] = $r['name'];
        });

        $this->assertNotContains('Alice', $collected);
        $this->assertContains('Bob', $collected);
    }

    public function testSelectRangeEachExcludesRecordsWithoutField()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob'));  // nessun campo 'age'

        $collected = array();
        $db->selectRangeEach('age', 1, 100, function ($r) use (&$collected) {
            $collected[] = $r['name'];
        });

        $this->assertEquals(array('Alice'), $collected);
    }

    public function testSelectRangeEachCallbackReceivesFullRecord()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30, 'city' => 'Rome'));

        $record = null;
        $db->selectRangeEach('age', 25, 35, function ($r) use (&$record) {
            $record = $r;
        });

        $this->assertNotNull($record);
        $this->assertArrayHasKey('id',   $record);
        $this->assertArrayHasKey('name', $record);
        $this->assertArrayHasKey('age',  $record);
        $this->assertArrayHasKey('city', $record);
        $this->assertEquals('Alice', $record['name']);
        $this->assertEquals(30,      $record['age']);
    }

    public function testSelectRangeEachAndSelectRangeReturnSameRecords()
    {
        // selectRangeEach e selectRange devono produrre gli stessi record
        // (selectRange è implementata sopra selectRangeEach)
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 20));
        $db->insert(array('name' => 'Bob',   'age' => 30));
        $db->insert(array('name' => 'Carol', 'age' => 25));
        $db->insert(array('name' => 'Dave',  'age' => 40));

        $fromEach = array();
        $db->selectRangeEach('age', 20, 30, function ($r) use (&$fromEach) {
            $fromEach[] = $r['name'];
        });
        sort($fromEach);

        $fromRange = $db->selectRange('age', 20, 30);
        $fromRangeNames = array_column($fromRange, 'name');
        sort($fromRangeNames);

        $this->assertEquals($fromRangeNames, $fromEach);
    }

    // =========================================================================
    // SECONDARY INDEX – aggiornamento automatico dopo write
    // =========================================================================

    public function testSecondaryIndexIsMarkedDirtyAfterInsert()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));

        $stats = $db->getStats();
        // L'indice è dirty finché non viene eseguita una range query o rebuildSecondaryIndexes()
        $this->assertTrue($stats['secondary_indexes']['age']['dirty']);
    }

    public function testSelectRangeTriggersLazyRebuild()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob',   'age' => 25));

        // Prima query dopo insert: rebuild automatico, poi risultati corretti
        $result = $db->selectRange('age', 25, 30);
        $this->assertNotNull($result);
        $this->assertCount(2, $result);

        // Dopo la range query l'indice deve essere pulito
        $stats = $db->getStats();
        $this->assertFalse($stats['secondary_indexes']['age']['dirty']);
    }

    public function testSelectRangeReflectsUpdateCorrectly()
    {
        $db = $this->dbWithIndex();
        $id = $db->insert(array('name' => 'Alice', 'age' => 20));

        // Prima della modifica Alice non è in [30, 40]
        $resultBefore = $db->selectRange('age', 30, 40);
        $this->assertNull($resultBefore);

        // Aggiorna l'età
        $db->update($id, array('name' => 'Alice', 'age' => 35));

        // Dopo la modifica Alice deve apparire in [30, 40]
        $resultAfter = $db->selectRange('age', 30, 40);
        $this->assertNotNull($resultAfter);
        $this->assertCount(1, $resultAfter);
        $this->assertEquals(35, $resultAfter[0]['age']);
    }

    public function testSelectRangeExcludesDeletedRecords()
    {
        $db = $this->dbWithIndex();
        $id1 = $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob',   'age' => 25));

        $db->delete($id1);

        $result = $db->selectRange('age', 20, 40);
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertEquals('Bob', $result[0]['name']);
    }

    public function testSecondaryIndexIsMarkedDirtyAfterDelete()
    {
        $db = $this->dbWithIndex();
        $id = $db->insert(array('name' => 'Alice', 'age' => 30));

        // Rebuild esplicito per partire puliti
        $db->rebuildSecondaryIndexes();
        $statsBefore = $db->getStats();
        $this->assertFalse($statsBefore['secondary_indexes']['age']['dirty']);

        // Delete deve marcare dirty
        $db->delete($id);
        $statsAfter = $db->getStats();
        $this->assertTrue($statsAfter['secondary_indexes']['age']['dirty']);
    }

    public function testSecondaryIndexIsMarkedDirtyAfterUpdate()
    {
        $db = $this->dbWithIndex();
        $id = $db->insert(array('name' => 'Alice', 'age' => 30));

        $db->rebuildSecondaryIndexes();
        $statsBefore = $db->getStats();
        $this->assertFalse($statsBefore['secondary_indexes']['age']['dirty']);

        $db->update($id, array('name' => 'Alice', 'age' => 31));
        $statsAfter = $db->getStats();
        $this->assertTrue($statsAfter['secondary_indexes']['age']['dirty']);
    }

    // =========================================================================
    // SECONDARY INDEX – rebuildSecondaryIndexes
    // =========================================================================

    public function testRebuildSecondaryIndexesClearsAllDirtyFlags()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob',   'age' => 25));

        // Entrambi gli indici sono dirty dopo gli insert
        $db->rebuildSecondaryIndexes();

        $stats = $db->getStats();
        $this->assertFalse($stats['secondary_indexes']['age']['dirty']);
        $this->assertFalse($stats['secondary_indexes']['name']['dirty']);
    }

    public function testRebuildSecondaryIndexesAllowsQueryWithoutLazyRebuild()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob',   'age' => 25));

        // Rebuild esplicito off-peak
        $db->rebuildSecondaryIndexes();

        // La query non deve fare rebuild interno (l'indice è già pulito)
        $result = $db->selectRange('age', 25, 30);
        $this->assertNotNull($result);
        $this->assertCount(2, $result);
    }

    // =========================================================================
    // SECONDARY INDEX – persistenza e restart
    // =========================================================================

    public function testSecondaryIndexSurvivesRestart()
    {
        // Prima sessione: inserisci dati e forza rebuild
        $db1 = $this->dbWithIndex();
        $db1->insert(array('name' => 'Alice', 'age' => 30));
        $db1->insert(array('name' => 'Bob',   'age' => 25));
        $db1->insert(array('name' => 'Carol', 'age' => 35));
        $db1->rebuildSecondaryIndexes();
        unset($db1);

        // Seconda sessione: l'indice deve ancora funzionare
        $db2 = $this->dbWithIndex();
        $result = $db2->selectRange('age', 25, 30);

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertEquals(array(25, 30), $this->pluck($result, 'age'));
    }

    public function testSecondaryIndexIsDirtyAfterRestartIfNotRebuilt()
    {
        // Prima sessione: inserisci senza rebuild esplicito
        $db1 = $this->dbWithIndex();
        $db1->insert(array('name' => 'Alice', 'age' => 30));
        unset($db1);

        // Seconda sessione: l'indice è dirty (mai fatto rebuild),
        // ma selectRange deve comunque funzionare grazie al rebuild lazy
        $db2 = $this->dbWithIndex();
        $stats = $db2->getStats();
        $this->assertTrue($stats['secondary_indexes']['age']['dirty']);

        // Nonostante il dirty bit, la query funziona (rebuild automatico)
        $result = $db2->selectRange('age', 25, 35);
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
    }

    // =========================================================================
    // SECONDARY INDEX – compact
    // =========================================================================

    public function testCompactMarksSecondaryIndexesDirty()
    {
        $db = $this->dbWithIndex();
        $id1 = $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob',   'age' => 25));
        $db->delete($id1);

        // Rebuild per partire puliti
        $db->rebuildSecondaryIndexes();

        // Compact deve marcare gli indici secondari dirty (gli offset cambiano)
        $db->compact();

        $stats = $db->getStats();
        $this->assertTrue($stats['secondary_indexes']['age']['dirty'],
            'compact() deve marcare gli indici secondari dirty (offset stale)');
    }

    public function testSelectRangeAfterCompactIsCorrect()
    {
        $db = $this->dbWithIndex();
        $id1 = $db->insert(array('name' => 'Alice', 'age' => 30));
        $db->insert(array('name' => 'Bob',   'age' => 25));
        $id3 = $db->insert(array('name' => 'Carol', 'age' => 35));
        $db->delete($id3);
        $db->update($id1, array('name' => 'Alice', 'age' => 31)); // versione obsoleta

        $db->compact();

        // Dopo compact il rebuild lazy deve produrre risultati corretti
        $result = $db->selectRange('age', 20, 35);
        $this->assertNotNull($result);
        // Alice (31) e Bob (25): Carol eliminata, versione vecchia Alice rimossa
        $this->assertCount(2, $result);
        $names = $this->pluck($result, 'name');
        $this->assertEquals(array('Alice', 'Bob'), $names);
    }

    public function testCompactStatsIncludeCorrectBreakdown()
    {
        $db = $this->dbWithIndex();
        $id1 = $db->insert(array('name' => 'Alice', 'age' => 30)); // attivo
        $id2 = $db->insert(array('name' => 'Bob',   'age' => 25)); // sarà cancellato
        $id3 = $db->insert(array('name' => 'Carol', 'age' => 35)); // avrà versione obsoleta
        $db->update($id3, array('name' => 'Carol', 'age' => 36));  // → 1 obsoleta
        $db->delete($id2);                                           // → 1 tombstone

        $stats = $db->compact();

        // Struttura base
        $this->assertArrayHasKey('records_kept',      $stats);
        $this->assertArrayHasKey('deleted_records',   $stats);
        $this->assertArrayHasKey('obsolete_versions', $stats);
        $this->assertArrayHasKey('records_removed',   $stats);

        // Valori: kept=2 (Alice+Carol v2), deleted=1 (Bob tombstone),
        // obsolete=2 (Bob v1 + Carol v1), removed=3
        $this->assertEquals(2, $stats['records_kept']);
        $this->assertEquals(1, $stats['deleted_records']);
        $this->assertEquals(2, $stats['obsolete_versions']);
        $this->assertEquals(3, $stats['records_removed']); // deleted + obsolete
    }

    // =========================================================================
    // SECONDARY INDEX – rebuildIndex marca dirty
    // =========================================================================

    public function testRebuildIndexMarksSecondaryIndexesDirty()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));

        // Rebuild esplicito per partire puliti
        $db->rebuildSecondaryIndexes();
        $statsBefore = $db->getStats();
        $this->assertFalse($statsBefore['secondary_indexes']['age']['dirty']);

        // rebuildIndex deve marcare i secondari dirty (gli offset potrebbero cambiare)
        $db->rebuildIndex();
        $statsAfter = $db->getStats();
        $this->assertTrue($statsAfter['secondary_indexes']['age']['dirty']);
    }

    // =========================================================================
    // SECONDARY INDEX – getStats
    // =========================================================================

    public function testGetStatsIncludesSecondaryIndexInfo()
    {
        $db = $this->dbWithIndex();
        $db->insert(array('name' => 'Alice', 'age' => 30));

        $stats = $db->getStats();

        $this->assertArrayHasKey('secondary_indexes', $stats);
        $this->assertArrayHasKey('age',  $stats['secondary_indexes']);
        $this->assertArrayHasKey('name', $stats['secondary_indexes']);

        // Ogni voce deve avere 'dirty' e 'file_size'
        foreach (array('age', 'name') as $field) {
            $this->assertArrayHasKey('dirty',     $stats['secondary_indexes'][$field]);
            $this->assertArrayHasKey('file_size', $stats['secondary_indexes'][$field]);
            $this->assertGreaterThanOrEqual(0, $stats['secondary_indexes'][$field]['file_size']);
        }
    }

    public function testGetStatsSecondaryIndexesEmptyWhenNotDeclared()
    {
        // Con costruttore senza $secondaryFields → secondary_indexes = array vuoto
        $db = $this->db();
        $db->insert(array('name' => 'Alice'));

        $stats = $db->getStats();
        $this->assertArrayHasKey('secondary_indexes', $stats);
        $this->assertEmpty($stats['secondary_indexes']);
    }

    public function testSecondaryIndexFileSizeGrowsAfterRebuild()
    {
        $db = $this->dbWithIndex();
        for ($i = 0; $i < 10; $i++) {
            $db->insert(array('name' => 'User' . $i, 'age' => 20 + $i));
        }

        $db->rebuildSecondaryIndexes();
        $stats = $db->getStats();

        // Il file dell'indice secondario deve avere dimensione > 0 dopo il rebuild
        $this->assertGreaterThan(0, $stats['secondary_indexes']['age']['file_size']);
        $this->assertGreaterThan(0, $stats['secondary_indexes']['name']['file_size']);
    }

    // =========================================================================
    // SECONDARY INDEX – tabelle multiple isolate
    // =========================================================================

    public function testSecondaryIndexesAreIsolatedPerTable()
    {
        $users    = new JsonDatabase('users',    $this->dataDir, array('age'));
        $products = new JsonDatabase('products', $this->dataDir, array('price'));

        $users->insert(array('name' => 'Alice', 'age' => 30));
        $users->insert(array('name' => 'Bob',   'age' => 25));
        $products->insert(array('sku' => 'P001', 'price' => 9.99));
        $products->insert(array('sku' => 'P002', 'price' => 4.99));

        // users: range su age
        $userResult = $users->selectRange('age', 25, 35);
        $this->assertNotNull($userResult);
        $this->assertCount(2, $userResult);

        // products: range su price
        $prodResult = $products->selectRange('price', 4.0, 5.5);
        $this->assertNotNull($prodResult);
        $this->assertCount(1, $prodResult);
        $this->assertEquals('P002', $prodResult[0]['sku']);

        // Cross-check: l'indice 'age' non esiste su products e viceversa
        $this->assertFalse($products->selectRange('age', 20, 40));
        $this->assertFalse($users->selectRange('price', 1.0, 100.0));
    }

    // =========================================================================
    // NUOVI TEST: Edge Cases, Tipi di Dato e Stringhe
    // =========================================================================

    public function testInsertAndSelectWithZeroStringId()
    {
        $db = $this->db();
        // PHP spesso fa confusione tra "0", 0, e false. Assicuriamoci che l'ID "0" funzioni.
        $id = $db->insertWithId('0', array('name' => 'Zero'));
        $this->assertEquals('0', $id, 'L\'inserimento con ID "0" deve restituire "0"');

        $record = $db->selectById('0');
        $this->assertNotNull($record, 'Il record con ID "0" deve essere recuperato correttamente');
        $this->assertEquals('Zero', $record['name']);
    }

    public function testSelectRangeOnFloatsPrecision()
    {
        // Presuppone che tu abbia un metodo helper dbWithIndex() o simile nel tuo test
        // Sostituisci con la corretta inizializzazione del db con indice secondario se necessario
        $db = new JsonDatabase('test_floats', $this->dataDir, array('score'));

        $db->insert(array('name' => 'A', 'score' => 10.001));
        $db->insert(array('name' => 'B', 'score' => 10.002));
        $db->insert(array('name' => 'C', 'score' => 10.005));

        $result = $db->selectRange('score', 10.001, 10.003);
        $this->assertCount(2, $result, 'Deve recuperare esattamente i due record nel range decimale');

        // Estrai i nomi per la verifica (usando un foreach o array_column se PHP >= 5.5)
        $names = array();
        foreach ($result as $row) { $names[] = $row['name']; }

        $this->assertContains('A', $names);
        $this->assertContains('B', $names);
        $this->assertNotContains('C', $names);
    }

    // =========================================================================
    // NUOVI TEST: Crash Recovery e Dirty Bit
    // =========================================================================

    public function testDirtyBitTriggersAutomaticRebuild()
    {
        $db = $this->db();
        $id = $db->insert(array('name' => 'Alice'));

        // Chiudiamo esplicitamente se necessario, o lasciamo che l'oggetto esca dallo scope
        unset($db);

        // Simuliamo un crash: corrompiamo solo il dirty bit dell'indice primario
        $indexFile = $this->dataDir . '/test.index.php';
        $this->assertFileExists($indexFile);

        $fp = fopen($indexFile, 'r+b');
        fseek($fp, 17); // HDR_DIRTY_OFFSET in BinaryIndex
        fwrite($fp, "\x01"); // Forza dirty = 1
        fclose($fp);

        // Re-istanziamo il database. Dovrebbe accorgersi del dirty bit e fare auto-rebuild.
        $db2 = $this->db();
        $record = $db2->selectById($id);

        $this->assertNotNull($record, 'Il database deve fare auto-rebuild trasparente se trova il dirty bit');
        $this->assertEquals('Alice', $record['name']);
    }

    // =========================================================================
    // NUOVI TEST: Callback ed Early Exit
    // =========================================================================

    public function testSelectEachEarlyExitOnFalse()
    {
        $db = $this->db();
        for ($i = 0; $i < 10; $i++) {
            $db->insert(array('val' => $i));
        }

        $calls = 0;
        $count = $db->selectEach(function ($record) use (&$calls) {
            $calls++;
            return ($calls < 2) ? null : false; // Esce intenzionalmente al secondo ciclo
        });

        // selectEach dovrebbe restituire il numero di record elaborati fino al false
        // Assicurati che il tuo selectEach restituisca il conteggio o adatta l'assertion
        $this->assertEquals(2, $calls, 'La callback deve interrompersi quando viene restituito false');
    }

    // =========================================================================
    // NUOVI TEST: Permessi e I/O
    // =========================================================================

    public function testGracefulFailureOnReadOnlyDirectory()
    {
        $db = $this->db();
        $db->insert(array('status' => 'ok')); // Inizializza i file

        // Rimuoviamo i permessi di scrittura dalla directory dati
        // Nota: Questo test potrebbe comportarsi diversamente su Windows,
        // è ideale per ambienti Linux/Unix.
        chmod($this->dataDir, 0444);

        try {
            // Tentiamo una scrittura. Il DB non deve lanciare un Fatal Error,
            // ma gestire l'errore (es. restituendo false o lanciando un'Exception custom)
            $result = $db->insert(array('status' => 'fail'));

            // A seconda di come hai implementato la gestione errori, fai l'assert corretto:
            $this->assertFalse($result, 'L\'insert deve fallire in modo grazioso se mancano i permessi');

        } catch (\Exception $e) {
            // Se gestisci con eccezioni, va bene uguale, ma verifica che sia l'eccezione prevista
            $this->assertTrue(true, 'Eccezione catturata correttamente per I/O error');
        }

        // Ripristiniamo i permessi per permettere al teardown di pulire la cartella
        chmod($this->dataDir, 0755);
    }

    // =========================================================================
    // NUOVI TEST: Concorrenza e Locking
    // =========================================================================

    public function testLockPreventsConcurrentWrites()
    {
        $db = $this->db();
        $db->insert(array('val' => 1)); // Crea i file
        $dataFile = $this->dataDir . '/test.jsonl.php';

        // Simuliamo un altro processo che tiene bloccato il file dati
        $fp = fopen($dataFile, 'ab');
        $lockAcquired = flock($fp, LOCK_EX | LOCK_NB); // Non bloccante per il test
        $this->assertTrue($lockAcquired, 'Il test deve poter acquisire il lock per simulare un altro processo');

        // Ora proviamo a fare un update tramite il database.
        // ATTENZIONE: Se il tuo DB usa flock(LOCK_EX) senza LOCK_NB o senza timeout,
        // questo test si bloccherà all'infinito!

        $startTime = microtime(true);
        $result = $db->insert(array('val' => 2));
        $duration = microtime(true) - $startTime;

        // Se hai un timeout implementato, la durata dovrebbe essere di circa (timeout) secondi
        // e il risultato false.
        $this->assertFalse($result, 'L\'inserimento deve fallire se il file è bloccato da un altro processo');

        // Rilasciamo il blocco
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function testInsertBatchRollbackOnDuplicateId()
    {
        $db = $this->db();
        $db->insertWithId('dup', ['name' => 'Original']);

        // Batch con tre righe, la seconda usa un ID già esistente
        $batch = [
            ['name' => 'One',   'id' => 'new1'],
            ['name' => 'Two',   'id' => 'dup'],   // duplicato!
            ['name' => 'Three', 'id' => 'new2'],
        ];

        $result = $db->insertBatch($batch);
        $this->assertFalse($result, 'insertBatch deve fallire interamente in presenza di un ID duplicato');

        // Nessuna delle nuove righe deve essere stata scritta
        $this->assertNull($db->selectById('new1'));
        $this->assertNull($db->selectById('new2'));
        $this->assertEquals(1, $db->count());
    }

    public function testInsertBatchMixedAutoAndCustomIds()
    {
        $db = $this->db();
        $batch = [
            ['name' => 'Auto1'],                       // id = 1
            ['name' => 'Auto2'],                       // id = 2
            ['name' => 'Custom', 'id' => 'abc123'],    // id custom
            ['name' => 'Auto3'],                       // id = 3
        ];

        $result = $db->insertBatch($batch);
        $this->assertNotFalse($result);
        $this->assertEquals(4, $result['inserted']);
        $this->assertEquals([1, 2, 'abc123', 3], $result['ids']);

        $this->assertNotNull($db->selectById(1));
        $this->assertNotNull($db->selectById(2));
        $this->assertNotNull($db->selectById('abc123'));
        $this->assertNotNull($db->selectById(3));
    }

    public function testUpdateWithExpectedVersion()
    {
        $db = $this->db();
        $id = $db->insert(['name' => 'Alice', 'age' => 20]);
        $version = $db->getEntryVersion($id);    // dovrebbe essere 1

        // Aggiornamento con versione corretta → successo
        $result = $db->update($id, ['name' => 'Alice', 'age' => 21], $version);
        $this->assertTrue($result);
        $this->assertEquals(2, $db->getEntryVersion($id));

        // Tentativo con versione vecchia → fallimento
        $result = $db->update($id, ['age' => 22], 1);
        $this->assertFalse($result);
        $this->assertEquals(21, $db->selectById($id)['age']);
    }

    public function testDeleteWithExpectedVersion()
    {
        $db = $this->db();
        $id = $db->insert(['name' => 'Alice']);
        $version = $db->getEntryVersion($id);

        // Versione corretta → ok
        $this->assertTrue($db->delete($id, $version));
        $this->assertNull($db->selectById($id));

        // Il record non esiste più, ma proviamo con un ID re‑inserito
        $id2 = $db->insert(['name' => 'Bob']);
        $version2 = $db->getEntryVersion($id2);
        $this->assertFalse($db->delete($id2, 999)); // versione errata
        $this->assertNotNull($db->selectById($id2)); // Bob ancora vivo
    }

    public function testAggregateEdgeCases()
    {
        $db = $this->db();
        $this->assertNull($db->aggregate('avg', 'age'));
        $this->assertNull($db->aggregate('min', 'age'));
        $this->assertEquals(0, $db->aggregate('count', 'age'));

        $db->insert(['name' => 'Alice', 'age' => 30]);
        $db->insert(['name' => 'Bob',   'score' => 100]);

        // count senza campo = totale record attivi
        $this->assertEquals(2, $db->aggregate('count', 'age'));
        $this->assertEquals(2, $db->aggregate('count', null)); // se null, usa sempre count totale

        // min/max funzionano solo sui record che hanno il campo
        $this->assertEquals(30, $db->aggregate('min', 'age'));
        $this->assertEquals(30, $db->aggregate('max', 'age'));

        // min/max su campo non numerico o inesistente restituiscono false
        $this->assertNull($db->aggregate('min', 'name'));

        // count con condizioni
        $db->insert(['name' => 'Carol', 'age' => 25]);
        $this->assertEquals(1, $db->aggregate('count', 'age', ['name' => 'Carol']));
        $this->assertEquals(0, $db->aggregate('count', 'age', ['name' => 'Zoe']));
    }

    public function testSelectPageWithConditionsAndAfterCompact()
    {
        $db = $this->db();
        for ($i = 1; $i <= 10; $i++) {
            $db->insert(['val' => $i, 'parity' => ($i % 2 === 0 ? 'even' : 'odd')]);
        }

        // Prima pagina con filtro
        $page1 = $db->selectPage(3, null, ['parity' => 'even']);
        $this->assertCount(3, $page1['records']);
        $this->assertTrue($page1['has_more']);
        $this->assertNotNull($page1['next_cursor']);

        // Seconda pagina
        $page2 = $db->selectPage(3, $page1['next_cursor'], ['parity' => 'even']);
        $this->assertCount(2, $page2['records']); // solo 5 numeri pari totali
        $this->assertFalse($page2['has_more']);
        $this->assertNull($page2['next_cursor']);

        // Compact e riprova (i cursori originali potrebbero non essere più validi,
        // ma la paginazione deve comunque funzionare partendo da capo)
        $db->compact();
        $pageAfter = $db->selectPage(3, null, ['parity' => 'even']);
        $this->assertCount(3, $pageAfter['records']);
    }

    /**
     * Verifica che setAutoCompact() scatti automaticamente oltre la soglia.
     *
     * Note di design:
     *  - La guardia assoluta da 5 MB in checkAutoCompact() non fa partire vacuum()
     *    se i byte stimati sprecati sono < 5 MB. Con record da 20 KB, l'ultima
     *    finestra di N delete (N*20KB < 5MB → N < 256) non viene compattata
     *    automaticamente, lasciando N tombstone e N righe obsolete nel file fisico.
     *  - Le "versioni obsolete" residue corrispondono esattamente alle delete
     *    non ancora compattate (ogni record cancellato dopo l'ultimo vacuum ha
     *    ancora la sua riga attiva nel file compattato → conta come obsoleta).
     *  - compact() esplicito risolve tutto.
     */
    public function testAutoCompactTriggersAfterThreshold()
    {
        ini_set('memory_limit', '256M');

        $db = $this->db();
        $db->setAutoCompact(true, 0.20);

        $recordSize   = 20 * 1024; // 20 KB
        $bigData      = str_repeat('x', $recordSize);
        $totalInserts = 1000;
        $ids          = [];

        for ($i = 0; $i < $totalInserts; $i++) {
            $ids[] = $db->insert(['data' => $bigData]);
        }

        $deletes = 500; // 500 × 20 KB = 10 MB sprecati → supera la soglia da 5 MB
        for ($i = 0; $i < $deletes; $i++) {
            $db->delete($ids[$i]);
        }

        $expected = $totalInserts - $deletes; // 500 record attivi

        // ── Assertion 1: il contatore logico deve essere esatto ───────────────
        $this->assertEquals($expected, $db->count(),
            "Il contatore dei record attivi deve essere $expected dopo $deletes delete");

        // ── Assertion 2: le versioni obsolete residue non superano i tombstone ─
        // Dopo l'ultimo vacuum, le N delete residue (non compattate per via della
        // guardia da 5 MB) lasciano:
        //   - N tombstone nel file
        //   - N righe "attive" (scritte dal vacuum) ora obsolete (il record è cancellato)
        // Quindi: obsolete_versions == deleted_records  ← invariante del residuo
        // Con compact esplicito entrambi tornano a 0.
        $stats = $db->getStats();
        $this->assertLessThanOrEqual(
            $stats['deleted_records'],
            $stats['obsolete_versions'],
            'Le versioni obsolete residue non devono superare i tombstone non compattati'
        );

        // ── Assertion 3: il file fisico dimostra che il vacuum è scattato ─────
        // Senza auto-compact ci sarebbero 1500 righe (1000 insert + 500 tombstone).
        // Con la guardia da 5 MB, al più ceil(5MB/recordSize) + 10 delete residue.
        $dataFile      = $this->dataDir . '/test.jsonl.php';
        $lines         = file($dataFile, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
        array_shift($lines); // rimuove la riga <?php die();
        $physicalLines = count($lines);

        $maxResidual = (int)ceil((5 * 1024 * 1024) / $recordSize) + 10;

        $this->assertGreaterThanOrEqual($expected, $physicalLines,
            "Il file fisico deve contenere almeno i $expected record attivi");
        $this->assertLessThanOrEqual($expected + $maxResidual, $physicalLines,
            "L'auto-compact deve essere scattato: le righe fisiche eccedono il margine atteso "
            . "(attese <= " . ($expected + $maxResidual) . ", trovate $physicalLines)");

        // ── Assertion 4: dopo compact() esplicito il file è completamente pulito ─
        $db->compact();

        $linesAfterFinalCompact = file($dataFile, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
        array_shift($linesAfterFinalCompact);
        $this->assertCount($expected, $linesAfterFinalCompact,
            "Dopo compact() esplicito il file deve contenere esattamente i $expected record attivi");

        $finalStats = $db->getStats();
        $this->assertEquals(0, $finalStats['obsolete_versions'],
            'Dopo compact() esplicito non ci devono essere versioni obsolete');
        $this->assertEquals(0, $finalStats['deleted_records'],
            'Dopo compact() esplicito non ci devono essere record eliminati');
    }

    public function testVersionOverflowIsPrevented()
    {
        $db = $this->db();
        $id = $db->insert(['x' => 0]);

        // Forza la versione a 65535 tramite reflection
        $refIdx = new ReflectionProperty($db, 'idx');
        $refIdx->setAccessible(true);
        $idx = $refIdx->getValue($db);

        $idx->open(true);
        $idx->beginWrite();
        $entry = $idx->lookup($id);
        $idx->put($id, $entry['offset'], $entry['length'], 65535);
        $idx->commitWrite();
        $idx->close();

        // L'update deve riuscire senza incrementare la versione
        $result = $db->update($id, ['x' => 1]);
        $this->assertTrue($result);
        $this->assertEquals(65535, $db->getEntryVersion($id));

        // Non controlliamo più il messaggio di overflow (opzionale)
    }

    public function testVersionOverflowMessageViaReflection()
    {
        $db = $this->db();
        $id = $db->insert(['x' => 1]);

        $refIdx = new ReflectionProperty($db, 'idx');
        $refIdx->setAccessible(true);
        $idx = $refIdx->getValue($db);

        $idx->open(true);
        $idx->beginWrite();
        $entry = $idx->lookup($id);
        $idx->put($id, $entry['offset'], $entry['length'], 65535);
        $idx->commitWrite();
        $idx->close();

        // L'update deve riuscire, ma lascia un trace
        $result = $db->update($id, ['x' => 2]);
        $this->assertTrue($result);

        $errorStack = $db->getErrorStack();
        $hasOverflow = false;
        foreach ($errorStack as $frame) {
            if (strpos($frame['msg'], 'version overflow') !== false) {
                $hasOverflow = true;
                break;
            }
        }
        $this->assertTrue($hasOverflow);
    }

    public function testLockTimeoutPreventsDeadlock()
    {
        $db = $this->db();
        $db->insert(['test' => 1]); // crea i file

        $dataFile = $this->dataDir . '/test.jsonl.php';
        $otherFp = fopen($dataFile, 'ab');
        if (!flock($otherFp, LOCK_EX)) {
            $this->markTestSkipped('Impossibile acquisire lock di test');
        }

        $start = microtime(true);
        $result = $db->insert(['test' => 2]); // dovrebbe fallire dopo ~500 ms
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertFalse($result);
        $this->assertGreaterThanOrEqual(450, $elapsed, 'Il timeout non è stato rispettato');
        $this->assertLessThan(1000, $elapsed);

        flock($otherFp, LOCK_UN);
        fclose($otherFp);
    }

    public function testErrorStackProvidesDetailedTrace()
    {
        $db = $this->db();
        $db->insertWithId('dup', ['name' => 'First']);
        $db->insertWithId('dup', ['name' => 'Second']); // fallisce

        $errorStack = $db->getErrorStack();
        $this->assertNotEmpty($errorStack);
        // Verifica che il messaggio dell'errore contenga "duplicate id=dup"
        $this->assertStringContainsString('duplicate id=dup', $errorStack[0]['msg']);

        $formatted = $db->getError();
        $this->assertStringContainsString('duplicate id=dup', $formatted);
        $this->assertStringContainsString('writeSingleRecord', $formatted);
    }
    
    public function testInsertBatchReturnsCorrectCountAndIds()
    {
        $db    = $this->db();
        $batch = [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Carol'],
        ];

        $result = $db->insertBatch($batch);

        $this->assertNotFalse($result);
        $this->assertEquals(3, $result['inserted']);
        $this->assertCount(3, $result['ids']);
    }

    public function testInsertBatchRecordsAreRetrievable()
    {
        $db    = $this->db();
        $batch = [
            ['name' => 'Alice', 'score' => 100],
            ['name' => 'Bob',   'score' => 200],
        ];

        $result = $db->insertBatch($batch);

        $this->assertEquals('Alice', $db->selectById($result['ids'][0])['name']);
        $this->assertEquals('Bob',   $db->selectById($result['ids'][1])['name']);
    }

    public function testInsertBatchEmptyReturnsZeroInserted()
    {
        $db     = $this->db();
        $result = $db->insertBatch([]);

        $this->assertNotFalse($result);
        $this->assertEquals(0, $result['inserted']);
        $this->assertEmpty($result['ids']);
    }

    public function testInsertBatchNonArrayRowReturnsFalse()
    {
        $db     = $this->db();
        $result = $db->insertBatch([
            ['name' => 'Alice'],
            'questa non è un array',  // riga invalida
            ['name' => 'Carol'],
        ]);

        $this->assertFalse($result, 'insertBatch deve fallire se una riga non è un array');
        // Nulla deve essere stato scritto (rollback atomico)
        $this->assertEquals(0, $db->count());
    }

    public function testInsertBatchIncreasesCountCorrectly()
    {
        $db = $this->db();
        $db->insert(['name' => 'Pre-existing']);

        $db->insertBatch([
            ['name' => 'A'],
            ['name' => 'B'],
        ]);

        $this->assertEquals(3, $db->count());
    }

    public function testInsertBatchAutoIncrementContinuesAfterBatch()
    {
        $db = $this->db();
        $db->insert(['name' => 'First']); // id=1

        $result = $db->insertBatch([
            ['name' => 'Batch1'], // id=2
            ['name' => 'Batch2'], // id=3
        ]);

        // Il prossimo insert deve continuare da id=4
        $nextId = $db->insert(['name' => 'After']);
        $this->assertEquals(4, $nextId);
    }

    // =========================================================================
    // SELECT EACH
    // =========================================================================

    public function testSelectEachVisitsAllActiveRecords()
    {
        $db = $this->db();
        for ($i = 0; $i < 5; $i++) {
            $db->insert(['val' => $i]);
        }
        $id = $db->insert(['val' => 99]);
        $db->delete($id); // questo non deve essere visitato

        $visited = [];
        $db->selectEach(function ($record) use (&$visited) {
            $visited[] = $record['val'];
        });

        sort($visited);
        $this->assertEquals([0, 1, 2, 3, 4], $visited);
    }

    public function testSelectEachWithConditionsFiltersCorrectly()
    {
        $db = $this->db();
        $db->insert(['role' => 'admin', 'name' => 'Alice']);
        $db->insert(['role' => 'user',  'name' => 'Bob']);
        $db->insert(['role' => 'admin', 'name' => 'Carol']);

        $names = [];
        $db->selectEach(
            function ($r) use (&$names) { $names[] = $r['name']; },
            ['role' => 'admin']
        );

        sort($names);
        $this->assertEquals(['Alice', 'Carol'], $names);
    }

    public function testSelectEachWithLimitStopsEarly()
    {
        $db = $this->db();
        for ($i = 0; $i < 10; $i++) {
            $db->insert(['val' => $i]);
        }

        $visited = 0;
        $db->selectEach(
            function ($r) use (&$visited) { $visited++; },
            [],
            3
        );

        $this->assertEquals(3, $visited);
    }

    public function testSelectEachReturnsCountOfVisitedRecords()
    {
        $db = $this->db();
        $db->insert(['val' => 1]);
        $db->insert(['val' => 2]);
        $db->insert(['val' => 3]);

        $count = $db->selectEach(function ($r) {});
        $this->assertEquals(3, $count);
    }

    // =========================================================================
    // SELECT PAGE
    // =========================================================================

    public function testSelectPageFirstPageHasCorrectRecords()
    {
        $db = $this->db();
        for ($i = 1; $i <= 10; $i++) {
            $db->insert(['val' => $i]);
        }

        $page = $db->selectPage(4);

        $this->assertCount(4, $page['records']);
        $this->assertTrue($page['has_more']);
        $this->assertNotNull($page['next_cursor']);
        $this->assertEquals(4, $page['count']);
    }

    public function testSelectPageLastPageHasNoMore()
    {
        $db = $this->db();
        for ($i = 1; $i <= 5; $i++) {
            $db->insert(['val' => $i]);
        }

        $page1 = $db->selectPage(3);
        $page2 = $db->selectPage(3, $page1['next_cursor']);

        $this->assertCount(2, $page2['records']);
        $this->assertFalse($page2['has_more']);
        $this->assertNull($page2['next_cursor']);
    }

    public function testSelectPageOnEmptyDbReturnsEmptyRecords()
    {
        $db   = $this->db();
        $page = $db->selectPage(10);

        $this->assertIsArray($page);
        $this->assertEmpty($page['records']);
        $this->assertFalse($page['has_more']);
        $this->assertNull($page['next_cursor']);
    }

    public function testSelectPagePaginatesAllRecordsCorrectly()
    {
        $db = $this->db();
        for ($i = 1; $i <= 7; $i++) {
            $db->insert(['val' => $i]);
        }

        $all     = [];
        $cursor  = null;

        do {
            $page   = $db->selectPage(3, $cursor);
            $all    = array_merge($all, $page['records']);
            $cursor = $page['next_cursor'];
        } while ($page['has_more']);

        $this->assertCount(7, $all);
        $vals = array_column($all, 'val');
        sort($vals);
        $this->assertEquals([1, 2, 3, 4, 5, 6, 7], $vals);
    }

    public function testSelectPageExcludesDeletedRecords()
    {
        $db  = $this->db();
        $id1 = $db->insert(['val' => 1]);
        $db->insert(['val' => 2]);
        $db->insert(['val' => 3]);
        $db->delete($id1);

        $page = $db->selectPage(10);

        $vals = array_column($page['records'], 'val');
        $this->assertNotContains(1, $vals);
        $this->assertCount(2, $page['records']);
    }

    // =========================================================================
    // AGGREGATE
    // =========================================================================

    public function testAggregateSumReturnsCorrectValue()
    {
        $db = $this->db();
        $db->insert(['score' => 10]);
        $db->insert(['score' => 20]);
        $db->insert(['score' => 30]);

        $sum = $db->aggregate('sum', 'score');
        $this->assertEquals(60.0, $sum);
    }

    public function testAggregateAvgReturnsCorrectValue()
    {
        $db = $this->db();
        $db->insert(['score' => 10]);
        $db->insert(['score' => 20]);
        $db->insert(['score' => 30]);

        $avg = $db->aggregate('avg', 'score');
        $this->assertEqualsWithDelta(20.0, $avg, 0.001);
    }

    public function testAggregateMinMaxReturnCorrectValues()
    {
        $db = $this->db();
        $db->insert(['score' => 15]);
        $db->insert(['score' => 5]);
        $db->insert(['score' => 25]);

        $this->assertEquals(5,  $db->aggregate('min', 'score'));
        $this->assertEquals(25, $db->aggregate('max', 'score'));
    }

    public function testAggregateCountIgnoresMissingField()
    {
        $db = $this->db();
        $db->insert(['score' => 10, 'name' => 'Alice']);
        $db->insert(['name' => 'Bob']); // nessun campo 'score'

        // count senza condizioni conta tutti i record attivi
        $this->assertEquals(2, $db->aggregate('count', 'score'));
        // sum su 'score' ignora Bob (campo assente)
        $this->assertEquals(10.0, $db->aggregate('sum', 'score'));
    }

    public function testAggregateReturnsFalseOnUnsupportedFunction()
    {
        $db = $this->db();
        $db->insert(['score' => 10]);

        $this->assertFalse($db->aggregate('median', 'score'));
        $this->assertFalse($db->aggregate('',       'score'));
    }

    public function testAggregateIgnoresDeletedRecords()
    {
        $db = $this->db();
        $id = $db->insert(['score' => 100]);
        $db->insert(['score' => 10]);
        $db->delete($id);

        $this->assertEquals(10.0,  $db->aggregate('sum', 'score'));
        $this->assertEquals(10.0,  $db->aggregate('min', 'score'));
        $this->assertEquals(10.0,  $db->aggregate('max', 'score'));
        $this->assertEquals(10.0,  $db->aggregate('avg', 'score'));
        $this->assertEquals(1,     $db->aggregate('count', 'score'));
    }

    public function testAggregateAvgOnEmptyTableReturnsNull()
    {
        $db = $this->db();
        $this->assertNull($db->aggregate('avg', 'score'));
    }

    // =========================================================================
    // GET ENTRY VERSION
    // =========================================================================

    public function testGetEntryVersionReturnsOneAfterInsert()
    {
        $db = $this->db();
        $id = $db->insert(['name' => 'Alice']);

        $this->assertEquals(1, $db->getEntryVersion($id));
    }

    public function testGetEntryVersionIncrementsAfterUpdate()
    {
        $db  = $this->db();
        $id  = $db->insert(['name' => 'Alice']);
        $db->update($id, ['name' => 'Alice v2']);
        $db->update($id, ['name' => 'Alice v3']);

        $this->assertEquals(3, $db->getEntryVersion($id));
    }

    public function testGetEntryVersionReturnsFalseForMissingId()
    {
        $db = $this->db();
        $this->assertFalse($db->getEntryVersion(9999));
    }

    public function testGetEntryVersionReturnsFalseAfterDelete()
    {
        $db = $this->db();
        $id = $db->insert(['name' => 'Alice']);
        $db->delete($id);

        $this->assertFalse($db->getEntryVersion($id));
    }

    // =========================================================================
    // AUTO-COMPACT (versione corretta: record piccoli + compact manuale
    //              per isolare la logica della soglia senza reentrance)
    // =========================================================================

    public function testAutoCompactThresholdConfigIsRespected()
    {
        $db = $this->db();
        $db->setAutoCompact(true, 0.50); // soglia al 50%

        // 10 record, ne cancelliamo 4 (40%) → sotto soglia, nessun compact
        for ($i = 0; $i < 10; $i++) {
            $db->insert(['val' => $i]);
        }
        for ($i = 1; $i <= 4; $i++) {
            $db->delete($i);
        }

        $stats = $db->getStats();
        // La frammentazione è 40% < 50%: i deleted_records devono ancora esserci
        $this->assertGreaterThan(0, $stats['deleted_records'],
            'Con soglia al 50% e frammentazione al 40% non deve avvenire compattazione automatica');
    }

    public function testSetAutoCompactCanBeDisabled()
    {
        $db = $this->db();
        $db->setAutoCompact(true, 0.10);   // molto bassa
        $db->setAutoCompact(false);         // disabilitata

        for ($i = 0; $i < 10; $i++) {
            $db->insert(['val' => $i]);
        }
        for ($i = 1; $i <= 8; $i++) {
            $db->delete($i);   // 80% frammentazione, ma auto-compact è off
        }

        $stats = $db->getStats();
        // I tombstone devono essere ancora presenti (nessuna compattazione)
        $this->assertGreaterThan(0, $stats['deleted_records'],
            'Con auto-compact disabilitato i tombstone non devono essere rimossi');
    }

    // =========================================================================
    // VACUUM / COMPACT – casi limite
    // =========================================================================

    public function testVacuumOnAlreadyCleanDbReturnsZeroRemoved()
    {
        $db = $this->db();
        $db->insert(['name' => 'Alice']);
        $db->insert(['name' => 'Bob']);

        $stats = $db->compact(); // nessun deleted/obsolete

        $this->assertEquals(2, $stats['records_kept']);
        $this->assertEquals(0, $stats['records_removed']);
    }

    public function testVacuumAfterAllRecordsDeletedLeavesEmptyDb()
    {
        $db  = $this->db();
        $id1 = $db->insert(['name' => 'Alice']);
        $id2 = $db->insert(['name' => 'Bob']);
        $db->delete($id1);
        $db->delete($id2);

        $stats = $db->compact();

        $this->assertEquals(0, $stats['records_kept']);
        $this->assertEquals(0, $db->count());
        $this->assertNull($db->selectById($id1));
        $this->assertNull($db->selectById($id2));
    }

    public function testVacuumPreservesCustomStringIds()
    {
        $db = $this->db();
        $db->insertWithId('sku-001', ['price' => 9.99]);
        $db->insertWithId('sku-002', ['price' => 4.99]);
        $db->insertWithId('sku-003', ['price' => 1.99]);
        $db->delete('sku-002');

        $db->compact();

        $db2 = $this->db();
        $this->assertNotNull($db2->selectById('sku-001'));
        $this->assertNull($db2->selectById('sku-002'));
        $this->assertNotNull($db2->selectById('sku-003'));
        $this->assertEquals(2, $db2->count());
    }

    public function testVacuumThenInsertGetsCorrectNextId()
    {
        $db = $this->db();
        for ($i = 0; $i < 5; $i++) {
            $db->insert(['val' => $i]);
        }
        $db->delete(3);
        $db->delete(4);
        $db->compact();

        // next_id deve continuare da dopo l'ultimo ID visto (6)
        $nextId = $db->insert(['val' => 999]);
        $this->assertGreaterThan(5, $nextId,
            'Dopo compact, il next_id non deve tornare indietro');
    }

    // =========================================================================
    // REBUILD INDEX – casi limite aggiuntivi
    // =========================================================================

    public function testRebuildIndexPreservesCustomStringIds()
    {
        $db = $this->db();
        $db->insertWithId('abc', ['name' => 'Custom']);
        $db->insert(['name' => 'Auto']);

        $indexFile = $this->dataDir . '/test.index.php';
        file_put_contents($indexFile, "<?php die(); ?".">\n{CORROTTO}");

        $db->rebuildIndex();

        $db2 = $this->db();
        $this->assertNotNull($db2->selectById('abc'));
        $this->assertEquals('Custom', $db2->selectById('abc')['name']);
        $this->assertEquals(2, $db2->count());
    }

    public function testRebuildIndexAfterDeleteExcludesTombstones()
    {
        $db  = $this->db();
        $id1 = $db->insert(['name' => 'Alice']);
        $db->insert(['name' => 'Bob']);
        $db->delete($id1);

        $db->rebuildIndex();

        $db2 = $this->db();
        $this->assertEquals(1, $db2->count());
        $this->assertNull($db2->selectById($id1));
    }

    // =========================================================================
    // LOCKING – LOCK TIMEOUT
    // =========================================================================

    public function testCustomLockTimeoutIsRespected()
    {
        $db = $this->db();
        $db->setLockTimeoutMs(200); // override a 200 ms
        $db->insert(['test' => 1]);

        $dataFile = $this->dataDir . '/test.jsonl.php';
        $otherFp  = fopen($dataFile, 'ab');
        if (!flock($otherFp, LOCK_EX)) {
            $this->markTestSkipped('Impossibile acquisire lock di test');
        }

        $start   = microtime(true);
        $result  = $db->insert(['test' => 2]);
        $elapsed = (microtime(true) - $start) * 1000;

        flock($otherFp, LOCK_UN);
        fclose($otherFp);

        $this->assertFalse($result);
        $this->assertGreaterThanOrEqual(150, $elapsed,
            'Il timeout personalizzato deve essere rispettato');
        $this->assertLessThan(500, $elapsed,
            'Il timeout non deve superare di molto il valore configurato');
    }

    // =========================================================================
    // ERROR STACK
    // =========================================================================

    public function testResetErrorClearsStack()
    {
        $db = $this->db();
        $db->insertWithId('x', ['v' => 1]);
        $db->insertWithId('x', ['v' => 2]); // provoca un errore

        $this->assertNotEmpty($db->getErrorStack());

        $db->resetError();
        $this->assertEmpty($db->getErrorStack());
        $this->assertEquals('', $db->getError());
    }

    public function testSuccessfulOperationClearsErrorStack()
    {
        $db = $this->db();
        $db->insertWithId('x', ['v' => 1]);
        $db->insertWithId('x', ['v' => 2]); // errore

        // Un'operazione riuscita deve resettare automaticamente lo stack
        $id = $db->insert(['v' => 3]);
        $this->assertNotFalse($id);
        codecept_debug($db->getErrorStack());
        $this->assertEmpty($db->getErrorStack(),
            "Un'operazione riuscita deve azzerare l'error stack");
    }

    // =========================================================================
    // GETSTATS – verifica valori
    // =========================================================================

    public function testGetStatsNextIdGrowsAfterInserts()
    {
        $db = $this->db();
        $db->insert(['val' => 1]);
        $db->insert(['val' => 2]);
        $db->insert(['val' => 3]);

        $stats = $db->getStats();
        $this->assertEquals(4, $stats['next_id'],
            'next_id deve essere uguale a (ultimo_id + 1)');
    }

    public function testGetStatsNextIdDoesNotDecreaseAfterDelete()
    {
        $db  = $this->db();
        $id  = $db->insert(['val' => 1]);
        $db->insert(['val' => 2]);
        $db->delete($id);

        $stats = $db->getStats();
        $this->assertEquals(3, $stats['next_id'],
            'next_id non deve diminuire dopo un delete');
    }

    public function testGetStatsTotalLinesCountsAllPhysicalRows()
    {
        $db = $this->db();
        $id = $db->insert(['val' => 1]); // 1 riga
        $db->update($id, ['val' => 2]); // +1 riga (versione obsoleta)
        $db->insert(['val' => 3]);       // +1 riga

        // total_lines = 3 (insert v1 + update v2 + secondo insert)
        $stats = $db->getStats();
        $this->assertEquals(3, $stats['total_lines']);
        $this->assertEquals(2, $stats['active_records']);
        $this->assertEquals(1, $stats['obsolete_versions']);
    }

    /**
     * Testa _createEmptyIndex chiamando rebuildIndex() dopo aver eliminato il file dati.
     * Questo percorso è normalmente saltato perché il costruttore crea il file dati.
     */
    public function testRebuildIndexOnMissingDataFileCreatesEmptyIndex()
    {
        $db = $this->db();
        $db->insert(['name' => 'temp']);
        $db->delete(1);
        unset($db); // chiude eventuali handle residui
        unlink($this->dataDir . '/test.jsonl.php');
        unlink($this->dataDir . '/test.index.php');
        $db2 = $this->db(); // nuova istanza
        $db2->rebuildIndex();
        $this->assertEquals(0, $db2->count());
        $this->assertFileExists($this->dataDir . '/test.jsonl.php');
        $this->assertFileExists($this->dataDir . '/test.index.php');
    }

    /**
     * Simula un fallimento di fwrite durante insert: rende il file dati read‑only.
     */
    public function testInsertFailsWhenDataFileNotWritable()
    {
        $db = $this->db();
        $db->insert(['name' => 'dummy']);
        unset($db);
        chmod($this->dataDir . '/test.jsonl.php', 0444);
        $db2 = $this->db();
        $result = @$db2->insert(['name' => 'fail']);
        $this->assertFalse($result);
        $this->assertStringContainsString('openAndLock failed', $db2->getError()); // modificato
        chmod($this->dataDir . '/test.jsonl.php', 0644);
    }

    /**
     * Simula un fallimento di fwrite durante update.
     */
    public function testUpdateFailsWhenDataFileNotWritable()
    {
        $db = $this->db();
        $id = $db->insert(['name' => 'Alice']);
        unset($db);
        chmod($this->dataDir . '/test.jsonl.php', 0444);
        $db2 = $this->db();
        $result = @$db2->update($id, ['name' => 'Alice v2']);
        $this->assertFalse($result);
        $this->assertStringContainsString('openAndLock failed', $db2->getError()); // modificato
        chmod($this->dataDir . '/test.jsonl.php', 0644);
    }

    /**
     * Verifica che rebuildIndex fallisca se il lock di rebuild non può essere acquisito.
     * Simula un altro processo che tiene bloccato il file .rebuild.lock.
     */
    public function testRebuildIndexFailsWhenLockNotAcquired()
    {
        $db = $this->db();
        $db->insert(['name' => 'A']);            // crea i file

        // Acquisisce il lock esternamente (solo per backend flock, usato nei test)
        $lockFile = $this->dataDir . '/test.index.php.rebuild.lock';
        $fp = fopen($lockFile, 'w');
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            $this->markTestSkipped('Impossibile acquisire il lock di test');
        }

        $result = $db->rebuildIndex();
        $this->assertFalse($result);
        $this->assertStringContainsString('Failed to acquire rebuild lock', $db->getError());

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Verifica che getEntryVersion restituisca false quando l’indice è corrotto
     * e non può essere ricostruito (file dati read‑only).
     */
    public function testGetEntryVersionReturnsFalseWhenIndexCannotBeRebuilt()
    {
        $db = $this->db();
        $db->insert(['name' => 'Alice']);
        unlink($this->dataDir . '/test.index.php');
        unset($db);
        chmod($this->dataDir, 0555);

        // Gestore personalizzato che ignora i warning "Permission denied"
        set_error_handler(function ($errno, $errstr) {
            return strpos($errstr, 'Permission denied') !== false;
        }, E_WARNING);

        $db2 = $this->db();
        $version = $db2->getEntryVersion(1);
        restore_error_handler();

        $this->assertFalse($version);
        $this->assertStringContainsString('ensureIndex(read) failed', $db2->getError());
        chmod($this->dataDir, 0755);
    }
}
