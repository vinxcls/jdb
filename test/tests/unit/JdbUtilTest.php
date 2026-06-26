<?php
/**
 * @file JdbUtilTest.php
 *
 * @brief Full test suite for the JdbUtil static class.
 *
 * Compatible with PHPUnit 9+ and Codeception Unit suite.
 *
 * Every test method is self-contained:
 *   - setUp() creates a fresh temp directory for filesystem operations.
 *   - tearDown() removes the temp directory recursively.
 *
 * Run with:
 *   vendor/bin/phpunit tests/unit/JdbUtilTest.php
 *   vendor/bin/codecept run unit JdbUtilTest
 */
class JdbUtilTest extends \PHPUnit\Framework\TestCase
{
    /** @var string Path to the per-test temporary directory. */
    private $tempDir;

    /** @var string Path to a pre-created file inside $tempDir. */
    private $tempFile;

    // ── Lifecycle ───────────────────────────────────────────────────────────

    /**
     * @brief Creates a fresh temporary directory and a pre-filled test file before each test.
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/jdb_util_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        $this->tempFile = $this->tempDir . '/test_file.dat';
        file_put_contents($this->tempFile, 'initial content');
    }

    /**
     * @brief Removes the temporary directory and all its contents after each test.
     */
    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    /**
     * @brief Recursively removes a directory and all its contents.
     *
     * @param string $dir Path to the directory to remove.
     */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =========================================================================
    // 1. ID & DATA NORMALIZATION
    // =========================================================================

    /**
     * @brief Verifies that numeric strings and integers are cast to their native types.
     *
     * @covers JdbUtil::normalizeId
     */
    public function testNormalizeIdConvertsNumericStringsToNumbers(): void
    {
        $this->assertSame(42, JdbUtil::normalizeId('42'));
        $this->assertSame(3.14, JdbUtil::normalizeId('3.14'));
        $this->assertSame(42, JdbUtil::normalizeId(42)); // already a native int
    }

    /**
     * @brief Verifies that non-numeric strings are left as strings.
     *
     * @covers JdbUtil::normalizeId
     */
    public function testNormalizeIdLeavesNonNumericStringsAsStrings(): void
    {
        $this->assertSame('abc-123', JdbUtil::normalizeId('abc-123'));
    }

    /**
     * @brief Verifies that normalizeId casts booleans and null through the string branch.
     *
     * is_numeric() returns false for booleans and null, so all three values fall
     * through to the (string) cast branch, never reaching the numeric (+0) path:
     *   true  → (string) → '1'  (is_numeric(true)  === false)
     *   false → (string) → ''   (is_numeric(false) === false)
     *   null  → (string) → ''   (is_numeric(null)  === false)
     *
     * @covers JdbUtil::normalizeId
     */
    public function testNormalizeIdWithBooleanAndNull(): void
    {
        $this->assertSame('1', JdbUtil::normalizeId(true));  // true  → (string) → '1'
        $this->assertSame('',  JdbUtil::normalizeId(false)); // false → (string) → ''
        $this->assertSame('',  JdbUtil::normalizeId(null));  // null  → (string) → ''
    }

    /**
     * @brief Verifies that isValidId returns the string form of a non-empty valid ID.
     *
     * @covers JdbUtil::isValidId
     */
    public function testIsValidIdReturnsStringIfValid(): void
    {
        $this->assertSame('valid_id_123', JdbUtil::isValidId('valid_id_123'));
    }

    /**
     * @brief Verifies that isValidId returns false for an empty string.
     *
     * An empty string is not a valid identifier in JDB. The original implementation
     * contained a bug where ($id !== 0) used strict inequality between a string and
     * an integer, which is always true, making empty strings incorrectly pass.
     * The corrected implementation returns false for ''.
     *
     * @covers JdbUtil::isValidId
     */
    public function testIsValidIdWithEmptyString(): void
    {
        $this->assertFalse(JdbUtil::isValidId(''));
    }

    /**
     * @brief Verifies that isNonEmptyArray correctly classifies arrays and other types.
     *
     * @covers JdbUtil::isNonEmptyArray
     */
    public function testIsNonEmptyArray(): void
    {
        $this->assertTrue(JdbUtil::isNonEmptyArray(['a' => 1]));
        $this->assertFalse(JdbUtil::isNonEmptyArray([]));
        $this->assertFalse(JdbUtil::isNonEmptyArray(null));
        $this->assertFalse(JdbUtil::isNonEmptyArray('string'));
    }

    /**
     * @brief Verifies jsonDecodeRecord with valid JSON, invalid JSON, and an empty string.
     *
     * @covers JdbUtil::jsonDecodeRecord
     */
    public function testJsonDecodeRecord(): void
    {
        $valid = '{"name":"Alice","age":30}';
        $this->assertSame(['name' => 'Alice', 'age' => 30], JdbUtil::jsonDecodeRecord($valid));

        $invalid = '{invalid json}';
        $this->assertNull(JdbUtil::jsonDecodeRecord($invalid));

        $this->assertNull(JdbUtil::jsonDecodeRecord(''));
    }

    /**
     * @brief Verifies that jsonDecodeRecord returns null when passed a PHP null value.
     *
     * json_decode(null) triggers a syntax error and json_last_error() returns
     * JSON_ERROR_SYNTAX, so the method must return null.
     *
     * @covers JdbUtil::jsonDecodeRecord
     */
    public function testJsonDecodeRecordWithNull(): void
    {
        $this->assertNull(JdbUtil::jsonDecodeRecord(null));
    }

    // =========================================================================
    // 2. FILESYSTEM HELPERS (File Handles)
    // =========================================================================

    /**
     * @brief Verifies that writePhpDieHeader writes exactly 16 bytes with the correct content.
     *
     * @covers JdbUtil::writePhpDieHeader
     */
    public function testWritePhpDieHeader(): void
    {
        $fp = fopen($this->tempFile, 'w');
        $result = JdbUtil::writePhpDieHeader($fp);
        fclose($fp);

        $this->assertTrue($result);
        $content = file_get_contents($this->tempFile);
        $this->assertStringStartsWith("<?php die(); ?>\n", $content);
        $this->assertSame(16, strlen($content)); // DATA_HEADER_SIZE
    }

    /**
     * @brief Verifies that getFileSize returns the correct byte count for a valid handle
     *        and false for non-resources.
     *
     * @covers JdbUtil::getFileSize
     */
    public function testGetFileSize(): void
    {
        $fp = fopen($this->tempFile, 'r');
        $this->assertSame(15, JdbUtil::getFileSize($fp)); // 'initial content' is 15 bytes
        fclose($fp);

        $this->assertFalse(JdbUtil::getFileSize('not_a_resource'));
    }

    /**
     * @brief Verifies that getFileSize returns false when the handle has already been closed.
     *
     * @covers JdbUtil::getFileSize
     */
    public function testGetFileSizeWithClosedResource(): void
    {
        $fp = fopen($this->tempFile, 'r');
        fclose($fp);
        $this->assertFalse(JdbUtil::getFileSize($fp));
    }

    /**
     * @brief Verifies that isValidRecordBounds accepts valid ranges and rejects invalid ones.
     *
     * @covers JdbUtil::isValidRecordBounds
     */
    public function testIsValidRecordBounds(): void
    {
        $fileSize = 100;
        $this->assertTrue(JdbUtil::isValidRecordBounds(10, 50, $fileSize));

        $this->assertFalse(JdbUtil::isValidRecordBounds(-1, 50,      $fileSize)); // negative offset
        $this->assertFalse(JdbUtil::isValidRecordBounds(10,  0,      $fileSize)); // zero length
        $this->assertFalse(JdbUtil::isValidRecordBounds(10, 200,     $fileSize)); // exceeds file size
        $this->assertFalse(JdbUtil::isValidRecordBounds(10, 20000000,$fileSize)); // exceeds MAX_RECORD_BYTES
    }

    /**
     * @brief Verifies that writeEmptySlots writes the expected number of zero-filled bytes.
     *
     * @covers JdbUtil::writeEmptySlots
     */
    public function testWriteEmptySlots(): void
    {
        $fp = fopen($this->tempFile, 'w');
        $slotSize = 10;
        $slots    = 5;

        $result = JdbUtil::writeEmptySlots($fp, $slots, $slotSize, 4096);
        fclose($fp);

        $this->assertTrue($result);
        $this->assertSame($slots * $slotSize, filesize($this->tempFile));

        $content = file_get_contents($this->tempFile);
        $this->assertSame(str_repeat("\x00", $slots * $slotSize), $content);
    }

    /**
     * @brief Verifies that writeEmptySlots with zero slots produces an empty file.
     *
     * @covers JdbUtil::writeEmptySlots
     */
    public function testWriteEmptySlotsWithZeroSlots(): void
    {
        $fp = fopen($this->tempFile, 'w');
        $result = JdbUtil::writeEmptySlots($fp, 0, 100, 4096);
        fclose($fp);

        $this->assertTrue($result);
        $this->assertSame(0, filesize($this->tempFile));
    }

    /**
     * @brief Verifies that writeEmptySlots forces slotsPerChunk = 1 when slotSize > chunkSize.
     *
     * When slotSize (20) exceeds chunkSize (16), floor(16/20) = 0, which is clamped to 1,
     * so each slot is written individually.
     *
     * @covers JdbUtil::writeEmptySlots
     */
    public function testWriteEmptySlotsWithSmallChunkSize(): void
    {
        $fp        = fopen($this->tempFile, 'w');
        $slotSize  = 20;
        $slots     = 3;
        $chunkSize = 16;

        $result = JdbUtil::writeEmptySlots($fp, $slots, $slotSize, $chunkSize);
        fclose($fp);

        $this->assertTrue($result);
        $this->assertSame($slots * $slotSize, filesize($this->tempFile));
        $content = file_get_contents($this->tempFile);
        $this->assertSame(str_repeat("\x00", $slots * $slotSize), $content);
    }

    // =========================================================================
    // 3. IDENTIFIER VALIDATION
    // =========================================================================

    /**
     * @brief Verifies that isValidIdentifier accepts alphanumeric names and underscores.
     *
     * @covers JdbUtil::isValidIdentifier
     */
    public function testIsValidIdentifierAcceptsValidNames(): void
    {
        $this->assertTrue(JdbUtil::isValidIdentifier('users'));
        $this->assertTrue(JdbUtil::isValidIdentifier('user_data_123'));
    }

    /**
     * @brief Verifies that isValidIdentifier rejects path-traversal sequences.
     *
     * @covers JdbUtil::isValidIdentifier
     */
    public function testIsValidIdentifierRejectsPathTraversal(): void
    {
        $this->assertFalse(JdbUtil::isValidIdentifier('../etc/passwd'));
        $this->assertFalse(JdbUtil::isValidIdentifier('folder/file'));
        $this->assertFalse(JdbUtil::isValidIdentifier('folder\\file'));
    }

    /**
     * @brief Verifies that isValidIdentifier rejects names exceeding MAX_IDENTIFIER_LEN (64).
     *
     * @covers JdbUtil::isValidIdentifier
     */
    public function testIsValidIdentifierRejectsTooLongNames(): void
    {
        $this->assertFalse(JdbUtil::isValidIdentifier(str_repeat('a', 65)));
    }

    /**
     * @brief Verifies that isValidIdentifier rejects hyphens and spaces under the default pattern.
     *
     * @covers JdbUtil::isValidIdentifier
     */
    public function testIsValidIdentifierRejectsInvalidCharacters(): void
    {
        $this->assertFalse(JdbUtil::isValidIdentifier('user-name')); // hyphen not allowed by default
        $this->assertFalse(JdbUtil::isValidIdentifier('user name')); // space not allowed
    }

    /**
     * @brief Verifies that isValidIdentifier rejects non-string inputs.
     *
     * @covers JdbUtil::isValidIdentifier
     */
    public function testIsValidIdentifierWithNonString(): void
    {
        $this->assertFalse(JdbUtil::isValidIdentifier(['array']));
        $this->assertFalse(JdbUtil::isValidIdentifier(123));
        $this->assertFalse(JdbUtil::isValidIdentifier(null));
    }

    /**
     * @brief Verifies that a custom pattern and maxLen override work correctly.
     *
     * A custom pattern can allow additional characters such as hyphens and dots,
     * but the path-traversal check always takes precedence regardless of pattern.
     *
     * @covers JdbUtil::isValidIdentifier
     */
    public function testIsValidIdentifierWithCustomPatternAndMaxLen(): void
    {
        $customPattern = '/^[a-zA-Z0-9_.-]+$/';

        $this->assertTrue(JdbUtil::isValidIdentifier('my-field.name', 100, $customPattern));
        $this->assertFalse(JdbUtil::isValidIdentifier('my-field.name', 5,   $customPattern)); // too long
        $this->assertFalse(JdbUtil::isValidIdentifier('my field',     100,  $customPattern)); // space disallowed
    }

    /**
     * @brief Verifies that isValidTableName and isValidFieldName behave as aliases of isValidIdentifier.
     *
     * @covers JdbUtil::isValidTableName
     * @covers JdbUtil::isValidFieldName
     */
    public function testIsValidTableNameAndFieldNameAreAliases(): void
    {
        $this->assertTrue(JdbUtil::isValidTableName('my_table'));
        $this->assertFalse(JdbUtil::isValidTableName(''));

        $this->assertTrue(JdbUtil::isValidFieldName('my_field'));
        $this->assertFalse(JdbUtil::isValidFieldName('../bad'));
    }

    // =========================================================================
    // 4. FILESYSTEM OPERATIONS
    // =========================================================================

    /**
     * @brief Verifies that ensureDirectory creates nested directories that did not exist.
     *
     * @covers JdbUtil::ensureDirectory
     */
    public function testEnsureDirectoryCreatesNestedDirectories(): void
    {
        $newDir = $this->tempDir . '/level1/level2/level3';
        $this->assertFalse(is_dir($newDir));

        $result = JdbUtil::ensureDirectory($newDir, 0755);

        $this->assertTrue($result);
        $this->assertTrue(is_dir($newDir));
    }

    /**
     * @brief Verifies that ensureDirectory returns true without error if the directory already exists.
     *
     * @covers JdbUtil::ensureDirectory
     */
    public function testEnsureDirectoryReturnsTrueIfAlreadyExists(): void
    {
        $this->assertTrue(JdbUtil::ensureDirectory($this->tempDir));
    }

    /**
     * @brief Verifies that safeOverwrite moves a source file to a new destination.
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteRenamesFileSuccessfully(): void
    {
        $source = $this->tempDir . '/source.txt';
        $dest   = $this->tempDir . '/dest.txt';

        file_put_contents($source, 'hello world');

        $error  = null;
        $result = JdbUtil::safeOverwrite($source, $dest, $error);

        $this->assertTrue($result);
        $this->assertNull($error);
        $this->assertFileExists($dest);
        $this->assertFileDoesNotExist($source);
        $this->assertSame('hello world', file_get_contents($dest));
    }

    /**
     * @brief Verifies that safeOverwrite replaces an existing destination file.
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteOverwritesExistingDestination(): void
    {
        $source = $this->tempDir . '/source.txt';
        $dest   = $this->tempDir . '/dest.txt';

        file_put_contents($source, 'new content');
        file_put_contents($dest,   'old content');

        $error  = null;
        $result = JdbUtil::safeOverwrite($source, $dest, $error);

        $this->assertTrue($result);
        $this->assertSame('new content', file_get_contents($dest));
    }

    /**
     * @brief Verifies that safeOverwrite returns false and sets $error when the source is missing.
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteFailsIfSourceMissing(): void
    {
        $source = $this->tempDir . '/missing.txt';
        $dest   = $this->tempDir . '/dest.txt';

        $error  = null;
        $result = JdbUtil::safeOverwrite($source, $dest, $error);

        $this->assertFalse($result);
        $this->assertStringContainsString('Source file does not exist', $error);
    }

    /**
     * @brief Verifies that safeOverwrite is a no-op and returns true when source === dest.
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteIsNoOpIfSourceAndDestAreSame(): void
    {
        $file = $this->tempDir . '/same.txt';
        file_put_contents($file, 'content');

        $error  = null;
        $result = JdbUtil::safeOverwrite($file, $file, $error);

        $this->assertTrue($result);
        $this->assertNull($error);
        $this->assertFileExists($file);
    }

    /**
     * @brief Verifies that safeOverwrite returns false when the destination directory does not exist.
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteWithInvalidDestinationDirectory(): void
    {
        $source = $this->tempDir . '/source.txt';
        $dest   = $this->tempDir . '/missing/subdir/file.txt';
        file_put_contents($source, 'test');

        $error  = null;
        $result = JdbUtil::safeOverwrite($source, $dest, $error);

        $this->assertFalse($result);
        $this->assertStringContainsString('Invalid destination directory', $error);
        $this->assertFileExists($source); // source must still be present
    }

    /**
     * @brief Verifies safeOverwrite returns false for a non-existent source (temp-based paths).
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteSourceNotExists(): void
    {
        $error  = null;
        $result = JdbUtil::safeOverwrite('/nonexistent', '/tmp/dest', $error);
        $this->assertFalse($result);
        $this->assertStringContainsString('Source file does not exist', $error);
    }

    /**
     * @brief Verifies safeOverwrite returns false when the destination directory is invalid.
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteInvalidDestDir(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'jdb_test_');
        $error  = null;
        $result = JdbUtil::safeOverwrite($source, '/nonexistent_dir/dest', $error);
        $this->assertFalse($result);
        $this->assertStringContainsString('Invalid destination directory', $error);
        @unlink($source);
    }

    /**
     * @brief Verifies safeOverwrite returns true immediately when source and destination are the same path.
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteSameFile(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'jdb_test_');
        $result = JdbUtil::safeOverwrite($source, $source);
        $this->assertTrue($result);
        @unlink($source);
    }

    /**
     * @brief Verifies safeOverwrite moves a file and preserves its content.
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteSuccess(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'jdb_test_');
        $dest   = tempnam(sys_get_temp_dir(), 'jdb_test_');
        @unlink($dest); // remove so rename can succeed on all platforms

        file_put_contents($source, 'test');
        $error  = null;
        $result = JdbUtil::safeOverwrite($source, $dest, $error);
        $this->assertTrue($result);
        $this->assertFileExists($dest);
        $this->assertEquals('test', file_get_contents($dest));
        @unlink($dest);
    }

    /**
     * @brief Verifies safeOverwrite fails and populates $error when rename() cannot overwrite a directory.
     *
     * Creating a directory at the destination path forces rename() to fail, which
     * exercises the error-reporting branch inside safeOverwrite().
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteRenameFailure(): void
    {
        $source  = $this->tempDir . '/source.txt';
        file_put_contents($source, 'content');

        // A directory at the destination path causes rename() to fail.
        $destDir = $this->tempDir . '/dest_dir';
        mkdir($destDir);
        $dest = $destDir;

        $error  = null;
        $result = JdbUtil::safeOverwrite($source, $dest, $error);

        $this->assertFalse($result);
        $this->assertStringContainsString('Rename failed', $error);
        $this->assertFileExists($source);
    }

    /**
     * @brief Verifies safeOverwrite atomically replaces an existing destination (general case).
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteWithExistingDestinationGeneral(): void
    {
        $source = $this->tempDir . '/src_gen.txt';
        $dest   = $this->tempDir . '/dst_gen.txt';
        file_put_contents($source, 'final');
        file_put_contents($dest,   'original');

        $result = JdbUtil::safeOverwrite($source, $dest);
        $this->assertTrue($result);
        $this->assertSame('final', file_get_contents($dest));
    }

    /**
     * @brief Verifies safeOverwrite replaces an existing destination file (additional coverage).
     *
     * @covers JdbUtil::safeOverwrite
     */
    public function testSafeOverwriteDestExistsAsFile(): void
    {
        $source = $this->tempDir . '/source.txt';
        $dest   = $this->tempDir . '/dest.txt';
        file_put_contents($source, 'new data');
        file_put_contents($dest,   'old data');

        $error  = null;
        $result = JdbUtil::safeOverwrite($source, $dest, $error);

        $this->assertTrue($result);
        $this->assertFileExists($dest);
        $this->assertFileDoesNotExist($source);
        $this->assertSame('new data', file_get_contents($dest));
    }

    // =========================================================================
    // 5. UTILITY FUNCTIONS
    // =========================================================================

    /**
     * @brief Verifies that randomSuffix returns a lowercase hex string of the expected length.
     *
     * @covers JdbUtil::randomSuffix
     */
    public function testRandomSuffixGeneratesValidHexString(): void
    {
        $suffix = JdbUtil::randomSuffix(8);
        $this->assertSame(16, strlen($suffix)); // 8 bytes = 16 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $suffix);
    }

    /**
     * @brief Verifies that randomSuffix respects a custom byte length.
     *
     * @covers JdbUtil::randomSuffix
     */
    public function testRandomSuffixWithCustomLength(): void
    {
        $suffix = JdbUtil::randomSuffix(4);
        $this->assertSame(8, strlen($suffix));
    }

    /**
     * @brief Verifies that two consecutive randomSuffix calls return different values.
     *
     * @covers JdbUtil::randomSuffix
     */
    public function testRandomSuffixUniqueness(): void
    {
        $suffix1 = JdbUtil::randomSuffix(8);
        $suffix2 = JdbUtil::randomSuffix(8);
        $this->assertNotEquals($suffix1, $suffix2);
    }

    /**
     * @brief Verifies recordMatchesConditions against various field/value combinations.
     *
     * @covers JdbUtil::recordMatchesConditions
     */
    public function testRecordMatchesConditions(): void
    {
        $record = ['id' => 1, 'name' => 'Alice', 'role' => 'admin'];

        $this->assertTrue(JdbUtil::recordMatchesConditions($record, ['name' => 'Alice']));
        $this->assertTrue(JdbUtil::recordMatchesConditions($record, ['id' => '1', 'role' => 'admin'])); // string cast match

        $this->assertFalse(JdbUtil::recordMatchesConditions($record, ['name' => 'Bob']));
        $this->assertFalse(JdbUtil::recordMatchesConditions($record, ['missing_field' => 'x']));
    }

    /**
     * @brief Verifies recordMatchesConditions handles null field values correctly.
     *
     * null is cast to '' when compared, so it matches an empty string condition.
     * A field missing from the record always returns false regardless of condition value.
     *
     * @covers JdbUtil::recordMatchesConditions
     */
    public function testRecordMatchesConditionsWithNullValues(): void
    {
        $record = ['id' => 1, 'deleted_at' => null, 'note' => ''];

        // null is cast to '' → matches an empty string condition
        $this->assertTrue(JdbUtil::recordMatchesConditions($record,  ['deleted_at' => '']));
        // null cast is '' → does not match the literal string 'null'
        $this->assertFalse(JdbUtil::recordMatchesConditions($record, ['deleted_at' => 'null']));
        // '' matches ''
        $this->assertTrue(JdbUtil::recordMatchesConditions($record,  ['note' => '']));
        // a missing field always returns false
        $this->assertFalse(JdbUtil::recordMatchesConditions($record, ['missing' => null]));
    }

    /**
     * @brief Verifies nextPowerOfTwo against a representative set of inputs.
     *
     * @covers JdbUtil::nextPowerOfTwo
     */
    public function testNextPowerOfTwo(): void
    {
        $this->assertSame(1,    JdbUtil::nextPowerOfTwo(0));
        $this->assertSame(1,    JdbUtil::nextPowerOfTwo(1));
        $this->assertSame(2,    JdbUtil::nextPowerOfTwo(2));
        $this->assertSame(4,    JdbUtil::nextPowerOfTwo(3));
        $this->assertSame(8,    JdbUtil::nextPowerOfTwo(8));
        $this->assertSame(16,   JdbUtil::nextPowerOfTwo(9));
        $this->assertSame(1024, JdbUtil::nextPowerOfTwo(1000));
    }

    /**
     * @brief Verifies nextPowerOfTwo returns 1 for zero and negative inputs.
     *
     * @covers JdbUtil::nextPowerOfTwo
     */
    public function testNextPowerOfTwoWithNegativeNumbers(): void
    {
        $this->assertSame(1, JdbUtil::nextPowerOfTwo(-5));
        $this->assertSame(1, JdbUtil::nextPowerOfTwo(-1));
        $this->assertSame(1, JdbUtil::nextPowerOfTwo(0));
    }

    /**
     * @brief Verifies that crc32u always returns a non-negative integer matching the unsigned CRC32 value.
     *
     * @covers JdbUtil::crc32u
     */
    public function testCrc32uReturnsUnsignedInteger(): void
    {
        $data   = 'test data for crc32';
        $result = JdbUtil::crc32u($data);

        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);

        // Normalise the reference value the same way the implementation does.
        $expected = crc32($data);
        $expected = ($expected < 0) ? ($expected + 4294967296) : $expected;
        $this->assertSame($expected, $result);
    }

    /**
     * @brief Verifies that crc32u('') returns 0 (the standard CRC32 of an empty input).
     *
     * @covers JdbUtil::crc32u
     */
    public function testCrc32uWithEmptyString(): void
    {
        $this->assertSame(0, JdbUtil::crc32u(''));
    }

    // =========================================================================
    // 6. PRIVATE METHOD COVERAGE (Windows & rename failure)
    // =========================================================================

    /**
     * @brief Verifies removeDestinationWindows successfully removes a file via Reflection.
     *
     * @covers JdbUtil::removeDestinationWindows
     */
    public function testRemoveDestinationWindowsSuccess(): void
    {
        $file = $this->tempDir . '/to_remove.txt';
        file_put_contents($file, 'test');
        $this->assertFileExists($file);

        $reflection = new ReflectionClass(JdbUtil::class);
        $method     = $reflection->getMethod('removeDestinationWindows');
        $method->setAccessible(true);

        $error = null;
        // invokeArgs requires an array; pass $error by reference explicitly.
        $args   = [$file, &$error];
        $result = $method->invokeArgs(null, $args);

        $this->assertTrue($result);
        $this->assertNull($error);
        $this->assertFileDoesNotExist($file);
    }

    /**
     * @brief Verifies unlinkWithRetry successfully removes an existing file via Reflection.
     *
     * @covers JdbUtil::unlinkWithRetry
     */
    public function testUnlinkWithRetrySuccess(): void
    {
        $file = $this->tempDir . '/to_unlink.txt';
        file_put_contents($file, 'test');
        $this->assertFileExists($file);

        $reflection = new ReflectionClass(JdbUtil::class);
        $method     = $reflection->getMethod('unlinkWithRetry');
        $method->setAccessible(true);

        $result = $method->invoke(null, $file);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($file);
    }

    /**
     * @brief Verifies unlinkWithRetry returns false when the target file does not exist.
     *
     * @covers JdbUtil::unlinkWithRetry
     */
    public function testUnlinkWithRetryOnMissingFile(): void
    {
        $file = $this->tempDir . '/does_not_exist.txt';

        $reflection = new ReflectionClass(JdbUtil::class);
        $method     = $reflection->getMethod('unlinkWithRetry');
        $method->setAccessible(true);

        $result = $method->invoke(null, $file);

        $this->assertFalse($result);
    }
}
