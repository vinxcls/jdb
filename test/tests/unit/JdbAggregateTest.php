<?php
use Codeception\Test\Unit;

/**
 * @file JdbAggregateTest.php
 * @brief End-to-end integration tests for JdbAggregate against the real JDB engine.
 *
 * @details
 * Each test runs in an isolated temporary filesystem directory.
 * JdbManager and JdbAggregate are fully reset between tests via _before()/_after().
 *
 * Test sections:
 *   1.  Configuration API and relation registry
 *   2.  ONE_TO_MANY – insert / read / count / aggregate
 *   3.  ONE_TO_MANY – validation: FK field must not appear in child rows
 *   4.  ONE_TO_MANY – syncChildren (insert-first order, FK validation)
 *   5.  ONE_TO_MANY – deleteWithChildren
 *   6.  ONE_TO_MANY – loadWith
 *   7.  MANY_TO_MANY – attach / getRelated / detach / count
 *   8.  MANY_TO_MANY – payload fields and attach validation
 *   9.  MANY_TO_MANY – batch_fetch_threshold and deleteWithChildren
 *   10. POLYMORPHIC   – getPolymorphic / loadWith
 *   11. TEMPORAL      – archive annotation / blocked writes / skipped deletes
 *   12. TEMPORAL      – syncChildren / getTemporalRange
 *   13. Error and boundary cases
 *   14. Configuration API – additional coverage
 *   15. ONE_TO_MANY – condition filters / aggregation edge cases
 *   16. syncChildren – boundary / error paths
 *   17. deleteWithChildren – boundary / error paths
 *   18. loadWith – multiple relations / error paths
 *   19. MANY_TO_MANY – aggregation / boundary paths
 *   20. POLYMORPHIC – boundary / type-incompatibility paths
 *   21. TEMPORAL – boundary / wrong-type paths
 *   22. Error API – detailed_errors=false, clearError idempotency
 *   23. insertWithChildren – additional type / table validation
 *   24. loadWith – additional coverage
 *   25. MANY_TO_MANY – additional boundary paths
 *   26. POLYMORPHIC – additional coverage
 *   27. TEMPORAL – additional coverage
 *   28. enforce_foreign_keys configuration
 *   29. Relation registry edge cases
 *   30. Cross-cutting / regression
 *
 * @covers JdbAggregate
 */
class JdbAggregateIntegrationTest extends Unit
{
    /** @var string $dataDir Absolute path to the per-test isolated data directory. */
    private $dataDir;

    // =========================================================================
    // Fixture setup / teardown
    // =========================================================================

    /**
     * @brief Creates a unique temporary directory and resets all engine state before each test.
     *
     * @details
     * A fresh directory is created for every test so that no data leaks between cases.
     * clearAllInstances(true) also wipes secondary-index configuration.
     * detailed_errors is enabled globally so that assertion messages on getLastError()
     * contain field and table names, making test failures easier to diagnose.
     *
     * @return void
     */
    protected function _before()
    {
        $this->dataDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'jdbagg_' . uniqid('', true);
        mkdir($this->dataDir, 0755, true);

        // Reset engine state; clearAllInstances(true) also wipes secondary-index config.
        JdbManager::clearAllInstances(true);
        JdbAggregate::resetAll();
        JdbConfig::configure(['data_dir' => $this->dataDir, 'detailed_errors' => true]);
    }

    /**
     * @brief Releases engine state and removes the temporary directory after each test.
     *
     * @details
     * The order mirrors _before() in reverse: relation registry and manager instances
     * are cleared first, then the filesystem is cleaned up.  Calling resetAll() before
     * _rmDir() prevents any pending file handles from blocking the deletion.
     *
     * @return void
     */
    protected function _after()
    {
        JdbAggregate::resetAll();
        JdbManager::clearAllInstances(true);
        $this->_rmDir($this->dataDir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @brief Inserts a row via the real engine and returns the generated ID.
     *
     * @details
     * Throws RuntimeException on failure so that test errors surface loudly
     * instead of silently propagating a false ID into subsequent assertions.
     *
     * @param  string $table  Target table name.
     * @param  array  $data   Field-value pairs to insert.
     * @return mixed          The generated record ID.
     * @throws RuntimeException If JdbManager::insert() returns false.
     */
    private function _ins($table, array $data)
    {
        $id = JdbManager::insert($table, $data);
        if ($id === false) {
            throw new RuntimeException("JdbManager::insert failed on '$table'");
        }
        return $id;
    }

    /**
     * @brief Retrieves a record by ID from the given table.
     *
     * @param  string $table  Table name to query.
     * @param  mixed  $id     Record ID to look up.
     * @return array|null     The record array, or null if not found.
     */
    private function _find($table, $id)
    {
        return JdbManager::findById($table, $id);
    }

    /**
     * @brief Registers a ONE_TO_MANY relation with the given parameters.
     *
     * @param  string $name    Relation name used as the registry key.
     * @param  string $parent  Parent table name.
     * @param  string $child   Child table name.
     * @param  string $fk      Foreign-key field name on the child table.
     * @return void
     */
    private function _defO2M($name, $parent, $child, $fk)
    {
        JdbAggregate::defineRelation($name, JdbRelationMeta::oneToMany($parent, $child, $fk));
    }

    /**
     * @brief Registers a MANY_TO_MANY relation with the given parameters.
     *
     * @param  string $name      Relation name used as the registry key.
     * @param  string $parent    Parent table name.
     * @param  string $child     Child table name.
     * @param  string $junction  Junction/pivot table name.
     * @param  string $leftFk    Foreign-key field pointing to the parent in the junction table.
     * @param  string $rightFk   Foreign-key field pointing to the child in the junction table.
     * @param  array  $payload   Optional list of extra payload field names stored in the junction.
     * @return void
     */
    private function _defM2M($name, $parent, $child, $junction, $leftFk, $rightFk,
                              array $payload = array())
    {
        JdbAggregate::defineRelation($name, JdbRelationMeta::manyToMany(
            $parent, $child, $junction, $leftFk, $rightFk, $payload
        ));
    }

    /**
     * @brief Recursively removes a directory and all of its contents.
     *
     * @details
     * Silently returns when $dir does not exist, making the method safe to
     * call even after a partial cleanup.  Files are unlinked before their
     * parent directories so that rmdir() never fails on a non-empty directory.
     *
     * @param  string $dir  Absolute path of the directory to remove.
     * @return void
     */
    private function _rmDir($dir)
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), array('.', '..')) as $f) {
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($p) ? $this->_rmDir($p) : unlink($p);
        }
        rmdir($dir);
    }

    /**
     * @brief Returns a Unix timestamp approximately one hour in the past.
     *
     * @details
     * Used as a safe archive boundary in TEMPORAL tests: events inserted with
     * occurred_at < _cutoff() are treated as archived, those with
     * occurred_at > _cutoff() are treated as active.
     *
     * @return int  Unix timestamp (time() - 3600).
     */
    private function _cutoff() { return time() - 3600; }

    /**
     * @brief Registers the 'entity_events' TEMPORAL relation used by temporal test sections.
     *
     * @param  int $cutoff  Archive cutoff Unix timestamp; pass 0 to disable archiving.
     * @return void
     */
    private function _defTemporal($cutoff = 0)
    {
        JdbAggregate::defineRelation('entity_events', JdbRelationMeta::temporal(
            'entities', 'events', 'entity_id', 'occurred_at', $cutoff
        ));
    }

    // =========================================================================
    // 1. Configuration API & relation registry
    // =========================================================================

    /**
     * @brief configure() returns true and stores the new value for a known key.
     *
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getConfig
     * @group  configuration
     *
     * @return void
     */
    public function testConfigureValidKeyReturnsTrue()
    {
        $this->assertTrue(JdbAggregate::configure(array('max_retries' => 5)));
        $this->assertSame(5, JdbAggregate::getConfig('max_retries'));
    }

    /**
     * @brief configure() returns false and sets a last error for an unknown key.
     *
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  configuration
     *
     * @return void
     */
    public function testConfigureUnknownKeyReturnsFalseAndSetsError()
    {
        $this->assertFalse(JdbAggregate::configure(array('nonexistent_key' => 1)));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief batch_fetch_threshold key exists in the default configuration with value 200.
     *
     * @covers JdbAggregate::getConfig
     * @group  configuration
     *
     * @return void
     */
    public function testBatchFetchThresholdKeyExistsWithDefault200()
    {
        $this->assertSame(200, JdbAggregate::getConfig('batch_fetch_threshold'));
    }

    /**
     * @brief resetAll() restores all configuration keys to their default values.
     *
     * @details
     * After resetting, data_dir must be reconfigured for the test infrastructure
     * to function correctly; only max_retries is asserted here.
     *
     * @covers JdbAggregate::resetAll
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getConfig
     * @group  configuration
     *
     * @return void
     */
    public function testResetAllRestoresConfigDefaults()
    {
        JdbAggregate::configure(array('max_retries' => 99));
        JdbAggregate::resetAll();
        JdbAggregate::configure(array('data_dir' => $this->dataDir)); // restore data_dir
        $this->assertSame(2, JdbAggregate::getConfig('max_retries'));
    }

    /**
     * @brief defineRelation() stores the correct metadata in the relation registry.
     *
     * @covers JdbAggregate::defineRelation
     * @covers JdbAggregate::getRelationMeta
     * @group  configuration
     *
     * @return void
     */
    public function testDefineRelationStoresMetaCorrectly()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $meta = JdbAggregate::getRelationMeta('order_lines');
        $this->assertInstanceOf(JdbRelationMeta::class, $meta);
        $this->assertSame('orders',   $meta->parentTable);
        $this->assertSame('lines',    $meta->childTable);
        $this->assertSame('order_id', $meta->fkField);
    }

    /**
     * @brief defineRelation() returns false when the relation name is an empty string.
     *
     * @covers JdbAggregate::defineRelation
     * @group  configuration
     *
     * @return void
     */
    public function testDefineRelationEmptyNameReturnsFalse()
    {
        $this->assertFalse(
            JdbAggregate::defineRelation('', JdbRelationMeta::oneToMany('a', 'b', 'fk'))
        );
    }

    /**
     * @brief defineRelation() returns false when the metadata object has an empty FK field.
     *
     * @covers JdbAggregate::defineRelation
     * @group  configuration
     *
     * @return void
     */
    public function testDefineRelationInvalidMetaReturnsFalse()
    {
        $bad          = JdbRelationMeta::oneToMany('a', 'b', 'fk');
        $bad->fkField = '';
        $this->assertFalse(JdbAggregate::defineRelation('bad', $bad));
    }

    /**
     * @brief hasRelation() and removeRelation() correctly track registry membership.
     *
     * @covers JdbAggregate::hasRelation
     * @covers JdbAggregate::removeRelation
     * @group  configuration
     *
     * @return void
     */
    public function testHasRelationAndRemoveRelation()
    {
        $this->_defO2M('rel', 'a', 'b', 'fk');
        $this->assertTrue(JdbAggregate::hasRelation('rel'));
        JdbAggregate::removeRelation('rel');
        $this->assertFalse(JdbAggregate::hasRelation('rel'));
    }

    // =========================================================================
    // 2. ONE_TO_MANY – insert / read / count / aggregate
    // =========================================================================

    /**
     * @brief insertWithChildren() creates the parent and all child records on disk,
     *        and injects the correct FK value into every child row.
     *
     * @covers JdbAggregate::insertWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testInsertWithChildrenCreatesParentAndChildrenOnDisk()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');

        $res = JdbAggregate::insertWithChildren(
            'orders', array('ref' => 'ORD-001'),
            'order_lines', array(
                array('amount' => 10),
                array('amount' => 20),
            )
        );

        $this->assertIsArray($res);
        $this->assertArrayHasKey('parent_id', $res);
        $this->assertCount(2, $res['child_ids']);

        // Parent exists on disk.
        $parent = $this->_find('orders', $res['parent_id']);
        $this->assertNotNull($parent);
        $this->assertSame('ORD-001', $parent['ref']);

        // Children exist on disk with the FK injected.
        foreach ($res['child_ids'] as $childId) {
            $child = $this->_find('lines', $childId);
            $this->assertNotNull($child);
            $this->assertSame(
                (string)$res['parent_id'],
                (string)$child['order_id'],
                'FK must match parent ID (type-safe string comparison)'
            );
        }
    }

    /**
     * @brief insertWithChildren() with an empty child array returns an empty child_ids list.
     *
     * @covers JdbAggregate::insertWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testInsertWithChildrenNoChildrenReturnsEmptyChildIds()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'EMPTY'), 'order_lines', array());
        $this->assertSame(array(), $res['child_ids']);
    }

    /**
     * @brief getRelated() on a ONE_TO_MANY relation returns all child records.
     *
     * @covers JdbAggregate::getRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testGetRelatedOneToManyReturnsAllChildren()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'X'), 'order_lines', array(
            array('amount' => 5),
            array('amount' => 10),
        ));

        $children = JdbAggregate::getRelated('order_lines', $res['parent_id']);
        $this->assertIsArray($children);
        $this->assertCount(2, $children);
    }

    /**
     * @brief getRelated() returns null when the parent exists but has no child records.
     *
     * @covers JdbAggregate::getRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testGetRelatedOneToManyReturnsNullWhenNoChildren()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $orderId = $this->_ins('orders', array('ref' => 'NO-CHILDREN'));
        $this->assertNull(JdbAggregate::getRelated('order_lines', $orderId));
    }

    /**
     * @brief getRelated() with a $limit argument returns at most that many rows.
     *
     * @covers JdbAggregate::getRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testGetRelatedOneToManyRespectsLimit()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'L'), 'order_lines', array(
            array('amount' => 1), array('amount' => 2),
            array('amount' => 3), array('amount' => 4),
        ));
        $limited = JdbAggregate::getRelated('order_lines', $res['parent_id'], array(), 2);
        $this->assertCount(2, $limited);
    }

    /**
     * @brief countRelated() returns the exact number of child records for a ONE_TO_MANY relation.
     *
     * @covers JdbAggregate::countRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testCountRelatedOneToMany()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'C'), 'order_lines', array(
            array('amount' => 1), array('amount' => 2), array('amount' => 3),
        ));
        $this->assertSame(3, JdbAggregate::countRelated('order_lines', $res['parent_id']));
    }

    /**
     * @brief sumRelated() returns the correct sum of a numeric field across all children.
     *
     * @covers JdbAggregate::sumRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testSumRelatedOneToMany()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'S'), 'order_lines', array(
            array('amount' => 10), array('amount' => 20), array('amount' => 30),
        ));
        $this->assertEqualsWithDelta(60.0,
            JdbAggregate::sumRelated('order_lines', $res['parent_id'], 'amount'), 0.001);
    }

    /**
     * @brief avgRelated() returns the correct arithmetic mean of a numeric field.
     *
     * @covers JdbAggregate::avgRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testAvgRelatedOneToMany()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'A'), 'order_lines', array(
            array('amount' => 10), array('amount' => 20), array('amount' => 30),
        ));
        $this->assertEqualsWithDelta(20.0,
            JdbAggregate::avgRelated('order_lines', $res['parent_id'], 'amount'), 0.001);
    }

    /**
     * @brief minRelated() and maxRelated() return the minimum and maximum values respectively.
     *
     * @covers JdbAggregate::minRelated
     * @covers JdbAggregate::maxRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testMinAndMaxRelatedOneToMany()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'MM'), 'order_lines', array(
            array('amount' => 5), array('amount' => 15), array('amount' => 10),
        ));
        $pid = $res['parent_id'];
        $this->assertEqualsWithDelta(5.0,
            JdbAggregate::minRelated('order_lines', $pid, 'amount'), 0.001);
        $this->assertEqualsWithDelta(15.0,
            JdbAggregate::maxRelated('order_lines', $pid, 'amount'), 0.001);
    }

    /**
     * @brief getRelated() is fully isolated between different parents sharing the same relation.
     *
     * @details
     * Children of parent A must never appear in the results for parent B and vice versa.
     *
     * @covers JdbAggregate::getRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testGetRelatedIsolatesBetweenDifferentParents()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $resA = JdbAggregate::insertWithChildren('orders', array('ref' => 'A'), 'order_lines',
            array(array('amount' => 100)));
        $resB = JdbAggregate::insertWithChildren('orders', array('ref' => 'B'), 'order_lines',
            array(array('amount' => 200)));

        $childrenA = JdbAggregate::getRelated('order_lines', $resA['parent_id']);
        $childrenB = JdbAggregate::getRelated('order_lines', $resB['parent_id']);

        $this->assertCount(1, $childrenA);
        $this->assertCount(1, $childrenB);
        $this->assertEqualsWithDelta(100, $childrenA[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(200, $childrenB[0]['amount'], 0.001);
    }

    // =========================================================================
    // 3. ONE_TO_MANY – validation: FK field must not appear in child rows
    // =========================================================================

    /**
     * @brief insertWithChildren() rejects a child row that already contains the FK field,
     *        sets a structured error message naming the offending field, and writes nothing.
     *
     * @details
     * The FK field is injected by JdbAggregate; providing it explicitly is an
     * error that could lead to data inconsistency.
     *
     * @covers JdbAggregate::insertWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testInsertWithChildrenRejectsChildRowThatAlreadyContainsFkField()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');

        $result = JdbAggregate::insertWithChildren('orders', array('ref' => 'X'), 'order_lines', array(
            array('order_id' => 99, 'amount' => 5), // FK field present → must be rejected
        ));

        $this->assertFalse($result);
        $err = JdbAggregate::getLastError();
        $this->assertNotNull($err);
        $this->assertStringContainsString('order_id', $err['message']);
        // No parent must have been written.
        $this->assertEmpty(JdbManager::findWhere('orders', array('ref' => 'X')) ?: array());
    }

    /**
     * @brief insertWithChildren() returns false when the parentTable does not match
     *        the relation's declared parent table.
     *
     * @covers JdbAggregate::insertWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testInsertWithChildrenRejectsWrongParentTable()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $this->assertFalse(
            JdbAggregate::insertWithChildren('invoices', array(), 'order_lines', array())
        );
    }

    // =========================================================================
    // 4. ONE_TO_MANY – syncChildren (insert-first order, FK validation)
    // =========================================================================

    /**
     * @brief syncChildren() inserts new rows BEFORE deleting old ones and returns
     *        correct inserted/deleted/skipped counts.
     *
     * @details
     * The insert-first order ensures that the child table is never momentarily
     * empty during a sync, which is important for concurrent readers.
     *
     * @covers JdbAggregate::syncChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenInsertsThenDeletes()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'SC'), 'order_lines', array(
            array('amount' => 10), array('amount' => 20),
        ));
        $pid = $res['parent_id'];

        $sync = JdbAggregate::syncChildren('order_lines', $pid, array(
            array('amount' => 99),
        ));

        $this->assertSame(1, $sync['inserted']);
        $this->assertSame(2, $sync['deleted']);
        $this->assertSame(0, $sync['skipped']);
    }

    /**
     * @brief New rows inserted by syncChildren() carry the correct FK value on disk.
     *
     * @covers JdbAggregate::syncChildren
     * @covers JdbAggregate::getRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenNewRowsHaveCorrectFkOnDisk()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'SK'), 'order_lines', array());
        $pid = $res['parent_id'];

        JdbAggregate::syncChildren('order_lines', $pid, array(array('amount' => 77)));

        // After sync, getRelated must return the new child with correct FK.
        $children = JdbAggregate::getRelated('order_lines', $pid);
        $this->assertCount(1, $children);
        $this->assertEqualsWithDelta(77, $children[0]['amount'], 0.001);
    }

    /**
     * @brief syncChildren() with an empty new-row array deletes all existing children.
     *
     * @covers JdbAggregate::syncChildren
     * @covers JdbAggregate::getRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenWithEmptyNewRowsDeletesAll()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'SE'), 'order_lines', array(
            array('amount' => 1), array('amount' => 2),
        ));
        $sync = JdbAggregate::syncChildren('order_lines', $res['parent_id'], array());
        $this->assertSame(0, $sync['inserted']);
        $this->assertSame(2, $sync['deleted']);
        $this->assertNull(JdbAggregate::getRelated('order_lines', $res['parent_id']));
    }

    /**
     * @brief syncChildren() rejects a new child row that already contains the FK field,
     *        sets a structured error message naming the offending field.
     *
     * @details
     * Mirrors the same guard applied by insertWithChildren().
     *
     * @covers JdbAggregate::syncChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenRejectsNewRowContainingFkField()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'SVF'), 'order_lines', array());
        $pid = $res['parent_id'];

        $result = JdbAggregate::syncChildren('order_lines', $pid, array(
            array('order_id' => 999, 'amount' => 5), // FK present → reject
        ));

        $this->assertFalse($result);
        $err = JdbAggregate::getLastError();
        $this->assertNotNull($err);
        $this->assertStringContainsString('order_id', $err['message']);
    }

    // =========================================================================
    // 5. ONE_TO_MANY – deleteWithChildren
    // =========================================================================

    /**
     * @brief deleteWithChildren() removes all child records and reports the correct counts.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenRemovesAllChildren()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'DEL'), 'order_lines', array(
            array('amount' => 1), array('amount' => 2),
        ));
        $pid = $res['parent_id'];
        $del = JdbAggregate::deleteWithChildren('order_lines', $pid);

        $this->assertSame(2, $del['deleted']);
        $this->assertSame(0, $del['skipped']);
        $this->assertNull(JdbAggregate::getRelated('order_lines', $pid));
    }

    /**
     * @brief deleteWithChildren() with $deleteParent=true also removes the parent record.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenAndDeleteParentRemovesParentToo()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'DP'), 'order_lines', array());
        $pid = $res['parent_id'];

        $del = JdbAggregate::deleteWithChildren('order_lines', $pid, true);
        $this->assertArrayHasKey('parent_deleted', $del);
        $this->assertTrue($del['parent_deleted']);
        $this->assertNull($this->_find('orders', $pid));
    }

    /**
     * @brief deleteWithChildren() with $deleteParent=false omits 'parent_deleted' from the result.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenWithoutDeleteParentOmitsKeyFromResult()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'NK'), 'order_lines', array());
        $del = JdbAggregate::deleteWithChildren('order_lines', $res['parent_id'], false);
        $this->assertArrayNotHasKey('parent_deleted', $del);
    }

    // =========================================================================
    // 6. ONE_TO_MANY – loadWith
    // =========================================================================

    /**
     * @brief loadWith() returns the parent record and the requested relation in a
     *        structured array with 'record' and 'relations' keys.
     *
     * @covers JdbAggregate::loadWith
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithReturnsParentAndRelation()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'LW'), 'order_lines', array(
            array('amount' => 7),
        ));
        $loaded = JdbAggregate::loadWith('orders', $res['parent_id'], array('order_lines'));
        $this->assertSame('LW', $loaded['record']['ref']);
        $this->assertCount(1, $loaded['relations']['order_lines']);
    }

    /**
     * @brief loadWith() returns null when the parent record does not exist.
     *
     * @covers JdbAggregate::loadWith
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithReturnsNullForMissingParent()
    {
        $this->assertNull(JdbAggregate::loadWith('orders', 'NONEXISTENT_ID', array()));
    }

    /**
     * @brief loadWith() applies per-relation limits supplied in the $limits map.
     *
     * @covers JdbAggregate::loadWith
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithAppliesPerRelationLimit()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'LIM'), 'order_lines', array(
            array('amount' => 1), array('amount' => 2), array('amount' => 3),
        ));
        $loaded = JdbAggregate::loadWith(
            'orders', $res['parent_id'],
            array('order_lines'),
            array('order_lines' => 2)
        );
        $this->assertCount(2, $loaded['relations']['order_lines']);
    }

    // =========================================================================
    // 7. MANY_TO_MANY – attach / getRelated / detach / count
    // =========================================================================

    /**
     * @brief attach() creates a junction row and getRelated() returns the child record.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::getRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachCreatesJunctionRowAndGetRelatedReturnsChild()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc A'));
        $tagId = $this->_ins('tags', array('name'  => 'php'));

        $junctionId = JdbAggregate::attach('doc_tags', $docId, $tagId);
        $this->assertNotFalse($junctionId);

        $related = JdbAggregate::getRelated('doc_tags', $docId);
        $this->assertCount(1, $related);
        $this->assertSame('php', $related[0]['name']);
    }

    /**
     * @brief attach() can associate multiple children with the same parent.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::countRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachMultipleChildrenToSameParent()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc B'));
        $tag1  = $this->_ins('tags', array('name' => 'php'));
        $tag2  = $this->_ins('tags', array('name' => 'oop'));

        JdbAggregate::attach('doc_tags', $docId, $tag1);
        JdbAggregate::attach('doc_tags', $docId, $tag2);

        $this->assertSame(2, JdbAggregate::countRelated('doc_tags', $docId));
    }

    /**
     * @brief Calling attach() twice for the same (parent, child) pair is idempotent:
     *        the junction table ends up with exactly one row.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::countRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachIsIdempotentOnDuplicateCall()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc C'));
        $tagId = $this->_ins('tags', array('name' => 'php'));

        JdbAggregate::attach('doc_tags', $docId, $tagId);
        JdbAggregate::attach('doc_tags', $docId, $tagId); // second attach

        $this->assertSame(1, JdbAggregate::countRelated('doc_tags', $docId));
    }

    /**
     * @brief detach() removes the junction row so that getRelated() returns null afterwards.
     *
     * @covers JdbAggregate::detach
     * @covers JdbAggregate::getRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testDetachRemovesRelation()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc D'));
        $tagId = $this->_ins('tags', array('name' => 'php'));

        JdbAggregate::attach('doc_tags', $docId, $tagId);
        JdbAggregate::detach('doc_tags', $docId, $tagId);

        $this->assertNull(JdbAggregate::getRelated('doc_tags', $docId));
    }

    /**
     * @brief detach() on a pair that was never attached is idempotent and returns true.
     *
     * @covers JdbAggregate::detach
     * @group  manyToMany
     *
     * @return void
     */
    public function testDetachIsIdempotentWhenNothingAttached()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc E'));
        $tagId = $this->_ins('tags', array('name' => 'php'));
        $this->assertTrue(JdbAggregate::detach('doc_tags', $docId, $tagId));
    }

    /**
     * @brief getRelated() on a MANY_TO_MANY relation returns null when no children are attached.
     *
     * @covers JdbAggregate::getRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testGetRelatedManyToManyReturnsNullWhenNothingAttached()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Alone'));
        $this->assertNull(JdbAggregate::getRelated('doc_tags', $docId));
    }

    /**
     * @brief getRelated() on a MANY_TO_MANY relation returns at most $limit records.
     *
     * @covers JdbAggregate::getRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testGetRelatedManyToManyRespectsLimit()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc F'));
        for ($i = 0; $i < 5; $i++) {
            $tagId = $this->_ins('tags', array('name' => 'tag' . $i));
            JdbAggregate::attach('doc_tags', $docId, $tagId);
        }
        $limited = JdbAggregate::getRelated('doc_tags', $docId, array(), 3);
        $this->assertCount(3, $limited);
    }

    /**
     * @brief countRelated() returns the exact number of attached children for a MANY_TO_MANY relation.
     *
     * @covers JdbAggregate::countRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testCountRelatedManyToMany()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc G'));
        $tag1  = $this->_ins('tags', array('name' => 't1'));
        $tag2  = $this->_ins('tags', array('name' => 't2'));
        $tag3  = $this->_ins('tags', array('name' => 't3'));
        JdbAggregate::attach('doc_tags', $docId, $tag1);
        JdbAggregate::attach('doc_tags', $docId, $tag2);
        JdbAggregate::attach('doc_tags', $docId, $tag3);

        $this->assertSame(3, JdbAggregate::countRelated('doc_tags', $docId));
    }

    // =========================================================================
    // 8. MANY_TO_MANY – payload fields and attach validation
    // =========================================================================

    /**
     * @brief attach() stores payload fields in the junction row and getRelated()
     *        exposes them under the '_junction' key on each result record.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::getRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachStoresPayloadAndGetRelatedExposesItUnderJunctionKey()
    {
        $this->_defM2M(
            'doc_tags', 'documents', 'tags', 'doc_tag_pivot',
            'document_id', 'tag_id', array('added_by', 'weight')
        );
        $docId = $this->_ins('documents', array('title' => 'Payload Doc'));
        $tagId = $this->_ins('tags', array('name' => 'important'));

        JdbAggregate::attach('doc_tags', $docId, $tagId, array('added_by' => 99, 'weight' => 5));

        $related = JdbAggregate::getRelated('doc_tags', $docId);
        $this->assertArrayHasKey('_junction', $related[0]);
        $this->assertSame('99', (string)$related[0]['_junction']['added_by']);
        $this->assertSame('5',  (string)$related[0]['_junction']['weight']);
    }

    /**
     * @brief A second attach() call for the same pair updates the payload and keeps only one row.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::countRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachUpdatesPayloadOnIdempotentCall()
    {
        $this->_defM2M(
            'doc_tags', 'documents', 'tags', 'doc_tag_pivot',
            'document_id', 'tag_id', array('weight')
        );
        $docId = $this->_ins('documents', array('title' => 'Update Doc'));
        $tagId = $this->_ins('tags', array('name' => 'tag'));

        JdbAggregate::attach('doc_tags', $docId, $tagId, array('weight' => 1));
        JdbAggregate::attach('doc_tags', $docId, $tagId, array('weight' => 9)); // update

        $related = JdbAggregate::getRelated('doc_tags', $docId);
        $this->assertSame('9', (string)$related[0]['_junction']['weight']);
        $this->assertSame(1, JdbAggregate::countRelated('doc_tags', $docId)); // still 1 row
    }

    /**
     * @brief attach() rejects a payload containing the reserved 'id' key.
     *
     * @covers JdbAggregate::attach
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachRejectsReservedIdFieldInPayload()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $this->assertFalse(JdbAggregate::attach('doc_tags', 1, 2, array('id' => 999)));
        $this->assertStringContainsString('"id"', JdbAggregate::getLastError()['message']);
    }

    /**
     * @brief attach() rejects a payload that contains a foreign-key field name.
     *
     * @covers JdbAggregate::attach
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachRejectsFkFieldsInPayload()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $this->assertFalse(JdbAggregate::attach('doc_tags', 1, 2, array('document_id' => 5)));
    }

    /**
     * @brief attach() rejects a payload key that contains a path-traversal sequence.
     *
     * @covers JdbAggregate::attach
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachRejectsInvalidPayloadFieldName()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        // Field name with path traversal → must be rejected.
        $this->assertFalse(JdbAggregate::attach('doc_tags', 1, 2, array('../bad' => 'x')));
    }

    // =========================================================================
    // 9. MANY_TO_MANY – batch_fetch_threshold and deleteWithChildren
    // =========================================================================

    /**
     * @brief When the number of related children exceeds batch_fetch_threshold,
     *        _queryManyToMany switches to a single-pass forEach on the child table;
     *        the result must still be complete and correct.
     *
     * @details
     * The threshold is set to 2 so that the batch code path is triggered with
     * only 3 children, keeping the test dataset minimal.
     *
     * @covers JdbAggregate::getRelated
     * @covers JdbAggregate::configure
     * @group  manyToMany
     *
     * @return void
     */
    public function testGetRelatedManyToManyLargeSetUsesForEachBatchPath()
    {
        // Set threshold to 2 so we trigger the batch path with only 3 children.
        JdbAggregate::configure(array('batch_fetch_threshold' => 2));
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');

        $docId = $this->_ins('documents', array('title' => 'Big Doc'));
        $ids   = array();
        for ($i = 0; $i < 3; $i++) {  // 3 > threshold(2) → batch path
            $tagId = $this->_ins('tags', array('name' => 'tag' . $i));
            JdbAggregate::attach('doc_tags', $docId, $tagId);
            $ids[] = $tagId;
        }

        $related = JdbAggregate::getRelated('doc_tags', $docId);
        $this->assertCount(3, $related);

        $names = array_column($related, 'name');
        sort($names);
        $this->assertSame(array('tag0', 'tag1', 'tag2'), $names);
    }

    /**
     * @brief deleteWithChildren() on a MANY_TO_MANY relation removes only junction rows,
     *        leaving the child (tag) records untouched.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @group  manyToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenManyToManyOnlyRemovesJunctionRows()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc H'));
        $tagId = $this->_ins('tags', array('name' => 'php'));
        JdbAggregate::attach('doc_tags', $docId, $tagId);

        $del = JdbAggregate::deleteWithChildren('doc_tags', $docId);
        $this->assertSame(1, $del['deleted']);

        // Tag record must still exist.
        $tag = $this->_find('tags', $tagId);
        $this->assertNotNull($tag);
        $this->assertSame('php', $tag['name']);
    }

    /**
     * @brief aggregateRelated() with function='count' works correctly on a MANY_TO_MANY relation.
     *
     * @covers JdbAggregate::aggregateRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAggregateRelatedCountManyToMany()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Agg Doc'));
        $tag1  = $this->_ins('tags', array('name' => 'a', 'score' => 10));
        $tag2  = $this->_ins('tags', array('name' => 'b', 'score' => 20));
        JdbAggregate::attach('doc_tags', $docId, $tag1);
        JdbAggregate::attach('doc_tags', $docId, $tag2);

        $this->assertSame(2, JdbAggregate::aggregateRelated('doc_tags', $docId, 'count', 'score'));
    }

    // =========================================================================
    // 10. POLYMORPHIC – getPolymorphic / loadWith
    // =========================================================================

    /**
     * @brief getPolymorphic() returns only the children scoped to the given parent type and ID,
     *        correctly excluding records belonging to a different parent type.
     *
     * @covers JdbAggregate::getPolymorphic
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicReturnsChildrenScopedToParentTypeAndId()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $orderId   = $this->_ins('orders',   array('ref' => 'ORD-P'));
        $invoiceId = $this->_ins('invoices', array('num' => 'INV-P'));

        $this->_ins('media', array('mediable_type' => 'orders',   'mediable_id' => (string)$orderId,   'url' => 'o1.pdf'));
        $this->_ins('media', array('mediable_type' => 'orders',   'mediable_id' => (string)$orderId,   'url' => 'o2.pdf'));
        $this->_ins('media', array('mediable_type' => 'invoices', 'mediable_id' => (string)$invoiceId, 'url' => 'i1.pdf'));

        $orderMedia   = JdbAggregate::getPolymorphic('media', 'orders',   $orderId);
        $invoiceMedia = JdbAggregate::getPolymorphic('media', 'invoices', $invoiceId);

        $this->assertCount(2, $orderMedia);
        $this->assertCount(1, $invoiceMedia);
    }

    /**
     * @brief getPolymorphic() returns null for a parent that has no associated child records.
     *
     * @covers JdbAggregate::getPolymorphic
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicReturnsNullForParentWithNoChildren()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $orderId = $this->_ins('orders', array('ref' => 'NO-MEDIA'));
        $this->assertNull(JdbAggregate::getPolymorphic('media', 'orders', $orderId));
    }

    /**
     * @brief getPolymorphic() returns false for an invalid (path-traversal) parent table name.
     *
     * @covers JdbAggregate::getPolymorphic
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicReturnsFalseForInvalidParentTable()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $this->assertFalse(JdbAggregate::getPolymorphic('media', '../bad', 1));
    }

    /**
     * @brief loadWith() correctly resolves a POLYMORPHIC relation alongside a ONE_TO_MANY relation.
     *
     * @covers JdbAggregate::loadWith
     * @group  polymorphic
     *
     * @return void
     */
    public function testLoadWithIncludesPolymorphicRelation()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $res     = JdbAggregate::insertWithChildren(
            'orders', array('ref' => 'POLY'), 'order_lines', array(array('amount' => 1))
        );
        $orderId = $res['parent_id'];
        $this->_ins('media', array(
            'mediable_type' => 'orders',
            'mediable_id'   => (string)$orderId,
            'url'           => 'file.pdf',
        ));

        $loaded = JdbAggregate::loadWith('orders', $orderId, array('order_lines', 'media'));
        $this->assertCount(1, $loaded['relations']['order_lines']);
        $this->assertCount(1, $loaded['relations']['media']);
    }

    // =========================================================================
    // 11. TEMPORAL – archive annotation / blocked writes / skipped deletes
    // =========================================================================

    /**
     * @brief getRelated() on a TEMPORAL relation annotates rows older than the cutoff
     *        with '_archived' = true and newer rows with '_archived' = false.
     *
     * @covers JdbAggregate::getRelated
     * @group  temporal
     *
     * @return void
     */
    public function testGetRelatedTemporalAnnotatesArchivedRowsCorrectly()
    {
        $cutoff = $this->_cutoff();
        $this->_defTemporal($cutoff);

        $entityId = $this->_ins('entities', array('name' => 'E1'));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff - 100));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff + 100));

        $rows     = JdbAggregate::getRelated('entity_events', $entityId);
        $archived = array_values(array_filter($rows, function ($r) { return !empty($r['_archived']); }));
        $active   = array_values(array_filter($rows, function ($r) { return isset($r['_archived']) && !$r['_archived']; }));

        $this->assertCount(1, $archived);
        $this->assertCount(1, $active);
    }

    /**
     * @brief getRelated() on a TEMPORAL relation with cutoff=0 does not add the '_archived' annotation.
     *
     * @covers JdbAggregate::getRelated
     * @group  temporal
     *
     * @return void
     */
    public function testGetRelatedTemporalNoCutoffDoesNotAnnotate()
    {
        $this->_defTemporal(0);
        $entityId = $this->_ins('entities', array('name' => 'E2'));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => time()));

        $rows = JdbAggregate::getRelated('entity_events', $entityId);
        $this->assertArrayNotHasKey('_archived', $rows[0]);
    }

    /**
     * @brief insertWithChildren() on a TEMPORAL relation rejects a child row whose
     *        occurred_at timestamp falls before the archive cutoff, and writes nothing.
     *
     * @covers JdbAggregate::insertWithChildren
     * @group  temporal
     *
     * @return void
     */
    public function testInsertWithChildrenRejectsArchivedTemporalRowsBeforeAnyWrite()
    {
        $cutoff = $this->_cutoff();
        $this->_defTemporal($cutoff);

        $result = JdbAggregate::insertWithChildren(
            'entities', array('name' => 'BLOCKED'), 'entity_events',
            array(array('occurred_at' => $cutoff - 1))
        );

        $this->assertFalse($result);
        // No entities table should have been written.
        $this->assertEmpty(JdbManager::findWhere('entities', array('name' => 'BLOCKED')) ?: array());
    }

    /**
     * @brief deleteWithChildren() on a TEMPORAL relation skips archived rows (does not delete them)
     *        and reports the correct deleted and skipped counts.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @group  temporal
     *
     * @return void
     */
    public function testDeleteWithChildrenTemporalSkipsArchivedRows()
    {
        $cutoff = $this->_cutoff();
        $this->_defTemporal($cutoff);

        $entityId = $this->_ins('entities', array('name' => 'E3'));
        $oldId    = $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff - 100));
        $newId    = $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff + 100));

        $del = JdbAggregate::deleteWithChildren('entity_events', $entityId);

        $this->assertSame(1, $del['deleted']);
        $this->assertSame(1, $del['skipped']);
        $this->assertNotNull($this->_find('events', $oldId)); // archived row still on disk
    }

    // =========================================================================
    // 12. TEMPORAL – syncChildren / getTemporalRange
    // =========================================================================

    /**
     * @brief syncChildren() on a TEMPORAL relation skips archived existing rows
     *        (does not attempt to delete them) and reports them in 'skipped'.
     *
     * @covers JdbAggregate::syncChildren
     * @group  temporal
     *
     * @return void
     */
    public function testSyncChildrenTemporalSkipsArchivedExistingRows()
    {
        $cutoff = $this->_cutoff();
        $this->_defTemporal($cutoff);

        $entityId = $this->_ins('entities', array('name' => 'E4'));
        $archId   = $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff - 100));

        $sync = JdbAggregate::syncChildren('entity_events', $entityId, array(
            array('occurred_at' => $cutoff + 500),
        ));

        $this->assertSame(1, $sync['inserted']);
        $this->assertSame(1, $sync['skipped']); // archived row not deleted
        $this->assertNotNull($this->_find('events', $archId));
    }

    /**
     * @brief syncChildren() on a TEMPORAL relation rejects a new row whose
     *        occurred_at timestamp falls before the archive cutoff.
     *
     * @covers JdbAggregate::syncChildren
     * @group  temporal
     *
     * @return void
     */
    public function testSyncChildrenTemporalRejectsArchivedNewRows()
    {
        $cutoff = $this->_cutoff();
        $this->_defTemporal($cutoff);
        $entityId = $this->_ins('entities', array('name' => 'E5'));

        $result = JdbAggregate::syncChildren('entity_events', $entityId, array(
            array('occurred_at' => $cutoff - 1),
        ));
        $this->assertFalse($result);
    }

    /**
     * @brief getTemporalRange() returns only the rows whose occurred_at falls
     *        within the given [fromTs, toTs] window.
     *
     * @covers JdbAggregate::getTemporalRange
     * @group  temporal
     *
     * @return void
     */
    public function testGetTemporalRangeFiltersRowsByTimestampWindow()
    {
        $this->_defTemporal(0);

        $entityId = $this->_ins('entities', array('name' => 'E6'));
        $ts1 = 1700000000;
        $ts2 = 1700000500;
        $ts3 = 1700001000;
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $ts1));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $ts2));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $ts3));

        // Range captures only the middle event.
        $rows = JdbAggregate::getTemporalRange('entity_events', $entityId, $ts1 + 1, $ts3 - 1);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame((string)$ts2, (string)$rows[0]['occurred_at']);
    }

    /**
     * @brief getTemporalRange() annotates archived rows within the result window
     *        with '_archived' = true when an archive cutoff is set.
     *
     * @covers JdbAggregate::getTemporalRange
     * @group  temporal
     *
     * @return void
     */
    public function testGetTemporalRangeAnnotatesArchivedRowsWithinWindow()
    {
        $cutoff = $this->_cutoff();
        $this->_defTemporal($cutoff);

        $entityId = $this->_ins('entities', array('name' => 'E7'));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff - 100));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff + 100));

        $rows = JdbAggregate::getTemporalRange('entity_events', $entityId, 0, 0);
        $this->assertIsArray($rows);
        $archived = array_filter($rows, function ($r) { return !empty($r['_archived']); });
        $this->assertCount(1, $archived);
    }

    /**
     * @brief getTemporalRange() returns false when fromTs is negative.
     *
     * @covers JdbAggregate::getTemporalRange
     * @group  temporal
     *
     * @return void
     */
    public function testGetTemporalRangeReturnsFalseForNegativeFromTs()
    {
        $this->_defTemporal(0);
        $this->assertFalse(JdbAggregate::getTemporalRange('entity_events', 1, -1, 0));
    }

    /**
     * @brief A second call to getTemporalRange() after a first call on the same parent
     *        must produce a consistent result without throwing or returning stale data.
     *
     * @details
     * NOTE – Coherence observation: the test name refers to "InvalidateIndexCache"
     * and the inline comments mention "invalidation", but no explicit cache-invalidation
     * method is called in the body.  If the intent is to verify implicit re-probing
     * after a second getTemporalRange() call, the test name should be updated to
     * something like testGetTemporalRangeIsRepeatableOnSameParent to avoid confusion.
     *
     * @covers JdbAggregate::getTemporalRange
     * @group  temporal
     *
     * @return void
     */
    public function testInvalidateIndexCacheForcesProbingAgain()
    {
        $this->_defTemporal(0);
        $entityId = $this->_ins('entities', array('name' => 'CACHE'));

        // First call populates the internal cache.
        JdbAggregate::getTemporalRange('entity_events', $entityId, 0, 0);

        // Second call must not throw or return stale data.
        $result = JdbAggregate::getTemporalRange('entity_events', $entityId, 0, 0);
        $this->assertNull($result); // no events inserted → null is correct
    }

    // =========================================================================
    // 13. Error / boundary cases
    // =========================================================================

    /**
     * @brief getRelated() returns false when parentId is null.
     *
     * @covers JdbAggregate::getRelated
     * @group  errorHandling
     *
     * @return void
     */
    public function testGetRelatedReturnsFalseForNullParentId()
    {
        $this->_defO2M('rel', 'orders', 'lines', 'order_id');
        $this->assertFalse(JdbAggregate::getRelated('rel', null));
    }

    /**
     * @brief getRelated() returns false and sets an error for an unknown relation name.
     *
     * @covers JdbAggregate::getRelated
     * @covers JdbAggregate::getLastError
     * @group  errorHandling
     *
     * @return void
     */
    public function testGetRelatedReturnsFalseForUnknownRelation()
    {
        $this->assertFalse(JdbAggregate::getRelated('does_not_exist', 1));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief getRelated() returns false when called on a POLYMORPHIC relation
     *        (wrong method for that relation type).
     *
     * @covers JdbAggregate::getRelated
     * @group  errorHandling
     *
     * @return void
     */
    public function testGetRelatedReturnsFalseForWrongRelationType()
    {
        JdbAggregate::defineRelation('poly', JdbRelationMeta::polymorphic('media', 'mt', 'mid'));
        $this->assertFalse(JdbAggregate::getRelated('poly', 1));
    }

    /**
     * @brief aggregateRelated() returns false for an unsupported aggregate function name.
     *
     * @covers JdbAggregate::aggregateRelated
     * @group  errorHandling
     *
     * @return void
     */
    public function testAggregateRelatedReturnsFalseForUnknownFunction()
    {
        $this->_defO2M('rel', 'orders', 'lines', 'order_id');
        $this->assertFalse(JdbAggregate::aggregateRelated('rel', 1, 'median', 'amount'));
    }

    /**
     * @brief aggregateRelated() returns false when the field name contains a path-traversal sequence.
     *
     * @covers JdbAggregate::aggregateRelated
     * @group  errorHandling
     *
     * @return void
     */
    public function testAggregateRelatedReturnsFalseForInvalidFieldName()
    {
        $this->_defO2M('rel', 'orders', 'lines', 'order_id');
        $this->assertFalse(JdbAggregate::aggregateRelated('rel', 1, 'sum', '../../bad'));
    }

    /**
     * @brief getLastError() returns null on a freshly initialised instance.
     *
     * @covers JdbAggregate::getLastError
     * @group  errorHandling
     *
     * @return void
     */
    public function testGetLastErrorIsNullInitially()
    {
        $this->assertNull(JdbAggregate::getLastError());
    }

    /**
     * @brief getLastError() returns a structured array with 'method', 'message', and 'time' keys
     *        after a failed operation.
     *
     * @covers JdbAggregate::getLastError
     * @group  errorHandling
     *
     * @return void
     */
    public function testGetLastErrorHasStructuredKeysAfterFailure()
    {
        JdbAggregate::getRelated('nonexistent', 1);
        $err = JdbAggregate::getLastError();
        $this->assertNotNull($err);
        $this->assertArrayHasKey('method',  $err);
        $this->assertArrayHasKey('message', $err);
        $this->assertArrayHasKey('time',    $err);
    }

    /**
     * @brief clearError() resets getLastError() to null.
     *
     * @covers JdbAggregate::clearError
     * @covers JdbAggregate::getLastError
     * @group  errorHandling
     *
     * @return void
     */
    public function testClearErrorResetsLastError()
    {
        JdbAggregate::getRelated('nonexistent', 1);
        JdbAggregate::clearError();
        $this->assertNull(JdbAggregate::getLastError());
    }

    /**
     * @brief detach() returns false when childId is null.
     *
     * @covers JdbAggregate::detach
     * @group  errorHandling
     *
     * @return void
     */
    public function testDetachReturnsFalseForNullChildId()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $this->assertFalse(JdbAggregate::detach('doc_tags', 1, null));
    }

    // =========================================================================
    // 14. Configuration API – additional coverage
    // =========================================================================

    /**
     * @brief configure() accepts and merges multiple valid keys in a single call.
     *
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getConfig
     * @group  configuration
     *
     * @return void
     */
    public function testConfigureMultipleValidKeysAtOnce()
    {
        $result = JdbAggregate::configure(array(
            'max_retries'     => 10,
            'retry_delay_ms'  => 200,
            'log_errors'      => false,
        ));
        $this->assertTrue($result);
        $this->assertSame(10,  JdbAggregate::getConfig('max_retries'));
        $this->assertSame(200, JdbAggregate::getConfig('retry_delay_ms'));
    }

    /**
     * @brief getConfig() returns null for a key that does not exist in the configuration.
     *
     * @covers JdbAggregate::getConfig
     * @group  configuration
     *
     * @return void
     */
    public function testGetConfigReturnsNullForUnknownKey()
    {
        $this->assertNull(JdbAggregate::getConfig('this_key_does_not_exist'));
    }

    /**
     * @brief clearRelations() removes every registered relation from the registry.
     *
     * @covers JdbAggregate::clearRelations
     * @covers JdbAggregate::hasRelation
     * @group  configuration
     *
     * @return void
     */
    public function testClearRelationsRemovesAllDefinedRelations()
    {
        $this->_defO2M('rel_a', 'orders',   'lines',  'order_id');
        $this->_defO2M('rel_b', 'invoices', 'items',  'invoice_id');
        JdbAggregate::clearRelations();

        $this->assertFalse(JdbAggregate::hasRelation('rel_a'));
        $this->assertFalse(JdbAggregate::hasRelation('rel_b'));
    }

    /**
     * @brief getRelationMeta() returns null for a relation name that was never registered.
     *
     * @covers JdbAggregate::getRelationMeta
     * @group  configuration
     *
     * @return void
     */
    public function testGetRelationMetaReturnsNullForUnknownRelation()
    {
        $this->assertNull(JdbAggregate::getRelationMeta('i_dont_exist'));
    }

    /**
     * @brief defineRelation() overwrites an existing relation when called with the same name
     *        and valid new metadata; the new metadata is immediately accessible.
     *
     * @covers JdbAggregate::defineRelation
     * @covers JdbAggregate::getRelationMeta
     * @group  configuration
     *
     * @return void
     */
    public function testDefineRelationOverwritesExistingRelation()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        // Redefine with a different FK.
        $this->_defO2M('order_lines', 'orders', 'lines', 'o_id');

        $meta = JdbAggregate::getRelationMeta('order_lines');
        $this->assertSame('o_id', $meta->fkField);
    }

    // =========================================================================
    // 15. ONE_TO_MANY – condition filters / aggregation edge cases
    // =========================================================================

    /**
     * @brief getRelated() applies extra $conditions and returns only matching rows.
     *
     * @covers JdbAggregate::getRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testGetRelatedOneToManyFiltersWithConditions()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'COND'), 'order_lines', array(
            array('amount' => 10, 'type' => 'A'),
            array('amount' => 20, 'type' => 'B'),
            array('amount' => 30, 'type' => 'A'),
        ));

        $typeA = JdbAggregate::getRelated(
            'order_lines', $res['parent_id'], array('type' => 'A')
        );

        $this->assertIsArray($typeA);
        $this->assertCount(2, $typeA);
        foreach ($typeA as $row) {
            $this->assertSame('A', $row['type']);
        }
    }

    /**
     * @brief countRelated() returns 0 (not null or false) when the parent exists
     *        but has no children.
     *
     * @covers JdbAggregate::countRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testCountRelatedReturnsZeroWhenNoChildren()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $orderId = $this->_ins('orders', array('ref' => 'CHILDLESS'));

        $count = JdbAggregate::countRelated('order_lines', $orderId);
        $this->assertSame(0, $count);
    }

    /**
     * @brief aggregateRelated() with function='count' works correctly on a ONE_TO_MANY relation.
     *
     * @covers JdbAggregate::aggregateRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testAggregateRelatedCountFunctionOneToMany()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'CNT'), 'order_lines', array(
            array('amount' => 1),
            array('amount' => 2),
        ));

        $count = JdbAggregate::aggregateRelated('order_lines', $res['parent_id'], 'count', 'amount');
        $this->assertSame(2, $count);
    }

    /**
     * @brief sumRelated() with an additional $conditions array sums only the matching rows.
     *
     * @covers JdbAggregate::sumRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testSumRelatedOneToManyWithConditions()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'SUMCOND'), 'order_lines', array(
            array('amount' => 10, 'status' => 'ok'),
            array('amount' => 20, 'status' => 'ok'),
            array('amount' => 100, 'status' => 'cancelled'),
        ));

        $sum = JdbAggregate::sumRelated(
            'order_lines', $res['parent_id'], 'amount', array('status' => 'ok')
        );
        $this->assertEqualsWithDelta(30.0, $sum, 0.001);
    }

    /**
     * @brief aggregateRelated() on an empty child set returns the additive identity (0.0)
     *        for sum and null for avg, min, and max.
     *
     * @details
     * sum returns 0.0 (additive identity) rather than null because summing zero
     * values is well-defined at the engine level.  avg, min, and max are undefined
     * for an empty set and therefore return null.
     *
     * @covers JdbAggregate::sumRelated
     * @covers JdbAggregate::avgRelated
     * @covers JdbAggregate::minRelated
     * @covers JdbAggregate::maxRelated
     * @group  oneToMany
     *
     * @return void
     */
    public function testAggregateRelatedReturnsNullWhenNoChildrenExist()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $orderId = $this->_ins('orders', array('ref' => 'EMPTY-AGG'));

        // sum returns the additive identity (0.0) rather than null.
        $this->assertEqualsWithDelta(0.0,
            JdbAggregate::sumRelated('order_lines', $orderId, 'amount'), 0.001);

        // avg / min / max return null when there are no numeric values.
        $this->assertNull(JdbAggregate::avgRelated('order_lines', $orderId, 'amount'));
        $this->assertNull(JdbAggregate::minRelated('order_lines', $orderId, 'amount'));
        $this->assertNull(JdbAggregate::maxRelated('order_lines', $orderId, 'amount'));
    }

    /**
     * @brief aggregateRelated() returns false and sets an error when parentId is null.
     *
     * @covers JdbAggregate::aggregateRelated
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testAggregateRelatedReturnsFalseForNullParentId()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $this->assertFalse(JdbAggregate::aggregateRelated('order_lines', null, 'sum', 'amount'));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief countRelated() returns false and sets an error when parentId is null.
     *
     * @covers JdbAggregate::countRelated
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testCountRelatedReturnsFalseForNullParentId()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $this->assertFalse(JdbAggregate::countRelated('order_lines', null));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief getRelated() returns false and reports an 'exceeded max_query_results' error
     *        when the result set would exceed the configured limit.
     *
     * @covers JdbAggregate::getRelated
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testGetRelatedRespectsMaxQueryResultsGlobalLimit()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', ['ref' => 'MAXQRY'], 'order_lines',
            array_fill(0, 20, ['amount' => 1]));
        JdbAggregate::configure(['max_query_results' => 5]);

        $result = JdbAggregate::getRelated('order_lines', $res['parent_id']);
        $this->assertFalse($result);
        $this->assertStringContainsString('exceeded max_query_results', JdbAggregate::getLastError()['message']);
    }

    /**
     * @brief getPolymorphic() returns false and reports an 'exceeded max_query_results' error
     *        when the result set would exceed the configured limit.
     *
     * @covers JdbAggregate::getPolymorphic
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicRespectsMaxQueryResultsGlobalLimit()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic('media', 'mediable_type', 'mediable_id'));
        $orderId = $this->_ins('orders', ['ref' => 'POLYMAX']);
        for ($i = 0; $i < 15; $i++) {
            $this->_ins('media', ['mediable_type' => 'orders', 'mediable_id' => (string)$orderId, 'url' => "$i.pdf"]);
        }
        JdbAggregate::configure(['max_query_results' => 7]);

        $result = JdbAggregate::getPolymorphic('media', 'orders', $orderId);
        $this->assertFalse($result);
        $this->assertStringContainsString('exceeded max_query_results', JdbAggregate::getLastError()['message']);
    }

    // =========================================================================
    // 16. syncChildren – boundary / error paths
    // =========================================================================

    /**
     * @brief syncChildren() returns false and sets an error when parentId is null.
     *
     * @covers JdbAggregate::syncChildren
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenReturnsFalseForNullParentId()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $this->assertFalse(JdbAggregate::syncChildren('order_lines', null, array()));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief syncChildren() returns false and sets an error for an unknown relation name.
     *
     * @covers JdbAggregate::syncChildren
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenReturnsFalseForUnknownRelation()
    {
        $this->assertFalse(JdbAggregate::syncChildren('no_such_relation', 1, array()));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief syncChildren() on a parent with no existing children returns inserted=1,
     *        deleted=0, skipped=0 when one new row is supplied.
     *
     * @covers JdbAggregate::syncChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenOnParentWithNoExistingChildrenReturnsZeroDeleted()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'FRESH'), 'order_lines', array());
        $pid = $res['parent_id'];

        $sync = JdbAggregate::syncChildren('order_lines', $pid, array(array('amount' => 5)));
        $this->assertSame(1, $sync['inserted']);
        $this->assertSame(0, $sync['deleted']);
        $this->assertSame(0, $sync['skipped']);
    }

    /**
     * @brief syncChildren() returns false and reports a 'Scan limit exceeded' error when
     *        the number of existing children exceeds the configured max_scan_rows limit.
     *
     * @covers JdbAggregate::syncChildren
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenAbortsWhenMaxScanRowsExceeded()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', ['ref' => 'SYNCSCAN'], 'order_lines',
            array_fill(0, 8, ['amount' => 1]));
        JdbAggregate::configure(['max_scan_rows' => 5]);

        $result = JdbAggregate::syncChildren('order_lines', $res['parent_id'], [['amount' => 99]]);
        $this->assertFalse($result);
        $this->assertStringContainsString('Scan limit exceeded', JdbAggregate::getLastError()['message']);
    }

    // =========================================================================
    // 17. deleteWithChildren – boundary / error paths
    // =========================================================================

    /**
     * @brief deleteWithChildren() returns false and sets an error when parentId is null.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenReturnsFalseForNullParentId()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $this->assertFalse(JdbAggregate::deleteWithChildren('order_lines', null));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief deleteWithChildren() returns false and sets an error for an unknown relation name.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenReturnsFalseForUnknownRelation()
    {
        $this->assertFalse(JdbAggregate::deleteWithChildren('no_such_relation', 1));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief deleteWithChildren() on a parent with zero children succeeds and reports
     *        deleted=0, skipped=0.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenOnParentWithNoChildrenReturnsZeroCounts()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'EMPTY-DEL'), 'order_lines', array());
        $del = JdbAggregate::deleteWithChildren('order_lines', $res['parent_id']);

        $this->assertSame(0, $del['deleted']);
        $this->assertSame(0, $del['skipped']);
    }

    /**
     * @brief deleteWithChildren() returns false with a 'Parent record does not exist' error
     *        when enforce_foreign_keys=true and the parent record is missing.
     *
     * @details
     * The parent is manually deleted via JdbManager to simulate a referential inconsistency
     * without going through JdbAggregate itself.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  foreignKeyEnforcement
     *
     * @return void
     */
    public function testDeleteWithChildrenReturnsFalseWhenParentMissingAndFkEnforced()
    {
        JdbAggregate::configure(['enforce_foreign_keys' => true]);
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $orderId = $this->_ins('orders', ['ref' => 'FKParent']);
        // Manually delete the parent to simulate a referential inconsistency.
        JdbManager::delete('orders', $orderId);

        $result = JdbAggregate::deleteWithChildren('order_lines', $orderId);
        $this->assertFalse($result);
        $this->assertStringContainsString('Parent record does not exist', JdbAggregate::getLastError()['message']);
    }

    /**
     * @brief deleteWithChildren() returns false and reports a 'Scan limit exceeded' error
     *        when the number of children exceeds the configured max_scan_rows limit.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenAbortsWhenMaxScanRowsExceeded()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', ['ref' => 'SCAN'], 'order_lines',
            array_fill(0, 10, ['amount' => 1]));
        JdbAggregate::configure(['max_scan_rows' => 5]);

        $result = JdbAggregate::deleteWithChildren('order_lines', $res['parent_id']);
        $this->assertFalse($result);
        $this->assertStringContainsString('Scan limit exceeded', JdbAggregate::getLastError()['message']);
    }

    // =========================================================================
    // 18. loadWith – multiple relations / error paths
    // =========================================================================

    /**
     * @brief loadWith() returns false and sets an error when parentTable contains a
     *        path-traversal sequence.
     *
     * @covers JdbAggregate::loadWith
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithReturnsFalseForInvalidParentTable()
    {
        $this->assertFalse(JdbAggregate::loadWith('../bad', 1, array()));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief loadWith() sets relations[name] = false for unknown relation names while
     *        still loading all other defined relations correctly.
     *
     * @covers JdbAggregate::loadWith
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithSetsRelationKeyToFalseForUnknownRelation()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res    = JdbAggregate::insertWithChildren('orders', array('ref' => 'MIX'), 'order_lines',
            array(array('amount' => 1)));
        $loaded = JdbAggregate::loadWith('orders', $res['parent_id'],
            array('order_lines', 'undefined_relation'));

        $this->assertCount(1, $loaded['relations']['order_lines']);
        $this->assertFalse($loaded['relations']['undefined_relation']);
    }

    /**
     * @brief loadWith() with multiple defined relations returns all of them in a single call.
     *
     * @covers JdbAggregate::loadWith
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithReturnsMultipleRelationsTogether()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));

        $res     = JdbAggregate::insertWithChildren(
            'orders', array('ref' => 'MULTI'), 'order_lines',
            array(array('amount' => 5), array('amount' => 10))
        );
        $orderId = $res['parent_id'];

        $this->_ins('media', array(
            'mediable_type' => 'orders',
            'mediable_id'   => (string)$orderId,
            'url'           => 'a.pdf',
        ));
        $this->_ins('media', array(
            'mediable_type' => 'orders',
            'mediable_id'   => (string)$orderId,
            'url'           => 'b.pdf',
        ));

        $loaded = JdbAggregate::loadWith('orders', $orderId, array('order_lines', 'media'));

        $this->assertSame('MULTI', $loaded['record']['ref']);
        $this->assertCount(2, $loaded['relations']['order_lines']);
        $this->assertCount(2, $loaded['relations']['media']);
    }

    /**
     * @brief loadWith() returns null (not false) when the parent record simply does not exist.
     *
     * @covers JdbAggregate::loadWith
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithReturnsNullNotFalseForNonExistentParent()
    {
        $result = JdbAggregate::loadWith('orders', 'GHOST_ID_99999', array());
        $this->assertNull($result);
    }

    // =========================================================================
    // 19. MANY_TO_MANY – aggregation / boundary paths
    // =========================================================================

    /**
     * @brief countRelated() on a MANY_TO_MANY relation returns 0 when no junction rows exist.
     *
     * @covers JdbAggregate::countRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testCountRelatedManyToManyReturnsZeroWhenNothingAttached()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'No Tags'));
        $this->assertSame(0, JdbAggregate::countRelated('doc_tags', $docId));
    }

    /**
     * @brief sumRelated() on a MANY_TO_MANY relation correctly aggregates a numeric field
     *        from the child table.
     *
     * @covers JdbAggregate::sumRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testSumRelatedManyToMany()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Sum Doc'));
        $tag1  = $this->_ins('tags', array('name' => 'x', 'score' => 10));
        $tag2  = $this->_ins('tags', array('name' => 'y', 'score' => 30));
        JdbAggregate::attach('doc_tags', $docId, $tag1);
        JdbAggregate::attach('doc_tags', $docId, $tag2);

        $this->assertEqualsWithDelta(40.0, JdbAggregate::sumRelated('doc_tags', $docId, 'score'), 0.001);
    }

    /**
     * @brief avgRelated() on a MANY_TO_MANY relation returns the arithmetic mean of the child field.
     *
     * @covers JdbAggregate::avgRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAvgRelatedManyToMany()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Avg Doc'));
        $tag1  = $this->_ins('tags', array('name' => 'a', 'score' => 10));
        $tag2  = $this->_ins('tags', array('name' => 'b', 'score' => 20));
        $tag3  = $this->_ins('tags', array('name' => 'c', 'score' => 30));
        JdbAggregate::attach('doc_tags', $docId, $tag1);
        JdbAggregate::attach('doc_tags', $docId, $tag2);
        JdbAggregate::attach('doc_tags', $docId, $tag3);

        $this->assertEqualsWithDelta(20.0, JdbAggregate::avgRelated('doc_tags', $docId, 'score'), 0.001);
    }

    /**
     * @brief attach() returns false and sets an error for an unknown relation name.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::getLastError
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachReturnsFalseForUnknownRelation()
    {
        $this->assertFalse(JdbAggregate::attach('no_relation', 1, 2));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief detach() returns false and sets an error for an unknown relation name.
     *
     * @covers JdbAggregate::detach
     * @covers JdbAggregate::getLastError
     * @group  manyToMany
     *
     * @return void
     */
    public function testDetachReturnsFalseForUnknownRelation()
    {
        $this->assertFalse(JdbAggregate::detach('no_relation', 1, 2));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief Attaching the same child to two different parents produces fully isolated
     *        junction rows: each parent's countRelated must remain 1.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::countRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachSameChildToDifferentParentsKeepsCountsIsolated()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docA  = $this->_ins('documents', array('title' => 'DocA'));
        $docB  = $this->_ins('documents', array('title' => 'DocB'));
        $tagId = $this->_ins('tags', array('name' => 'shared'));

        JdbAggregate::attach('doc_tags', $docA, $tagId);
        JdbAggregate::attach('doc_tags', $docB, $tagId);

        $this->assertSame(1, JdbAggregate::countRelated('doc_tags', $docA));
        $this->assertSame(1, JdbAggregate::countRelated('doc_tags', $docB));
    }

    /**
     * @brief All aggregate functions (sum, avg, min, max, count) work correctly in the
     *        large-set code path triggered when uniqueCount exceeds batch_fetch_threshold.
     *
     * @details
     * The threshold is set to 2 so that 5 attached children trigger the large-set path,
     * keeping the test dataset small while still exercising the branch.
     *
     * @covers JdbAggregate::sumRelated
     * @covers JdbAggregate::avgRelated
     * @covers JdbAggregate::minRelated
     * @covers JdbAggregate::maxRelated
     * @covers JdbAggregate::aggregateRelated
     * @covers JdbAggregate::configure
     * @group  manyToMany
     *
     * @return void
     */
    public function testAggregateManyToManyLargeSetUsesLargePath()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        // Set a low threshold to force the large-set code path (uniqueCount > threshold).
        JdbAggregate::configure(['batch_fetch_threshold' => 2]);

        $docId = $this->_ins('documents', ['title' => 'Large Agg Doc']);
        $scores = [10, 20, 30, 40, 50];
        foreach ($scores as $score) {
            $tagId = $this->_ins('tags', ['name' => 'tag_' . $score, 'score' => $score]);
            JdbAggregate::attach('doc_tags', $docId, $tagId);
        }

        // sum, avg, min, max must all work correctly in the large-set code path.
        $this->assertEqualsWithDelta(150.0, JdbAggregate::sumRelated('doc_tags', $docId, 'score'), 0.001);
        $this->assertEqualsWithDelta(30.0,  JdbAggregate::avgRelated('doc_tags', $docId, 'score'), 0.001);
        $this->assertEqualsWithDelta(10.0,  JdbAggregate::minRelated('doc_tags', $docId, 'score'), 0.001);
        $this->assertEqualsWithDelta(50.0,  JdbAggregate::maxRelated('doc_tags', $docId, 'score'), 0.001);
        $this->assertSame(5, JdbAggregate::aggregateRelated('doc_tags', $docId, 'count', 'score'));
    }

    // =========================================================================
    // 20. POLYMORPHIC – boundary / type-incompatibility paths
    // =========================================================================

    /**
     * @brief getPolymorphic() returns false and sets an error when parentId is null.
     *
     * @covers JdbAggregate::getPolymorphic
     * @covers JdbAggregate::getLastError
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicReturnsFalseForNullParentId()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $this->assertFalse(JdbAggregate::getPolymorphic('media', 'orders', null));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief countRelated() returns false and sets an error when called on a POLYMORPHIC
     *        relation (the method does not support that relation type).
     *
     * @covers JdbAggregate::countRelated
     * @covers JdbAggregate::getLastError
     * @group  polymorphic
     *
     * @return void
     */
    public function testCountRelatedReturnsFalseForPolymorphicRelation()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $this->assertFalse(JdbAggregate::countRelated('media', 1));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief getPolymorphic() scoped to a parent type that has no matching records returns null.
     *
     * @details
     * Media records belonging to the invoice parent must not appear in the result
     * for the order parent; the order query must return null, not an empty array.
     *
     * @covers JdbAggregate::getPolymorphic
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicReturnsNullForTypeWithNoMatchingRecords()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $orderId   = $this->_ins('orders', array('ref' => 'X'));
        $invoiceId = $this->_ins('invoices', array('num' => 'Y'));

        // Attach media only to the invoice.
        $this->_ins('media', array(
            'mediable_type' => 'invoices',
            'mediable_id'   => (string)$invoiceId,
            'url'           => 'inv.pdf',
        ));

        // Order has no media of its own.
        $this->assertNull(JdbAggregate::getPolymorphic('media', 'orders', $orderId));
    }

    // =========================================================================
    // 21. TEMPORAL – boundary / wrong-type paths
    // =========================================================================

    /**
     * @brief getTemporalRange() returns false and sets an error when called on a
     *        non-TEMPORAL relation (type mismatch).
     *
     * @covers JdbAggregate::getTemporalRange
     * @covers JdbAggregate::getLastError
     * @group  temporal
     *
     * @return void
     */
    public function testGetTemporalRangeReturnsFalseForNonTemporalRelation()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $this->assertFalse(JdbAggregate::getTemporalRange('order_lines', 1, 0, 0));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief getTemporalRange() returns false and sets an error when parentId is null.
     *
     * @covers JdbAggregate::getTemporalRange
     * @covers JdbAggregate::getLastError
     * @group  temporal
     *
     * @return void
     */
    public function testGetTemporalRangeReturnsFalseForNullParentId()
    {
        $this->_defTemporal(0);
        $this->assertFalse(JdbAggregate::getTemporalRange('entity_events', null, 0, 0));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief getTemporalRange() returns null (not false) when the parent exists
     *        but has no child events at all.
     *
     * @covers JdbAggregate::getTemporalRange
     * @group  temporal
     *
     * @return void
     */
    public function testGetTemporalRangeReturnsNullWhenNoEventsForParent()
    {
        $this->_defTemporal(0);
        $entityId = $this->_ins('entities', array('name' => 'NO-EVENTS'));

        $result = JdbAggregate::getTemporalRange('entity_events', $entityId, 0, 0);
        $this->assertNull($result);
    }

    /**
     * @brief getTemporalRange() with an open-ended upper bound (toTs=0) returns
     *        all events at or after fromTs.
     *
     * @details
     * toTs=0 is the sentinel value meaning "no upper bound"; the result must
     * include ts2 and ts3 but exclude ts1.
     *
     * @covers JdbAggregate::getTemporalRange
     * @group  temporal
     *
     * @return void
     */
    public function testGetTemporalRangeWithOpenEndedUpperBoundReturnsAllAfterFrom()
    {
        $this->_defTemporal(0);
        $entityId = $this->_ins('entities', array('name' => 'OPEN-END'));
        $ts1 = 1700000000;
        $ts2 = 1700001000;
        $ts3 = 1700002000;
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $ts1));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $ts2));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $ts3));

        // fromTs = ts2, toTs = 0 (open-ended) → must return ts2 and ts3 only.
        $rows = JdbAggregate::getTemporalRange('entity_events', $entityId, $ts2, 0);
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
    }

    // =========================================================================
    // 22. Error API – detailed_errors=false, clearError idempotency
    // =========================================================================

    /**
     * @brief When detailed_errors=false, getLastError() returns a generic message that
     *        does not expose internal names (table names, field names, path fragments).
     *        Structural keys 'method', 'message', and 'time' must still be present.
     *
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  errorHandling
     *
     * @return void
     */
    public function testGetLastErrorReturnsGenericMessageWhenDetailedErrorsIsDisabled()
    {
        // Re-configure: disable detailed errors, then trigger an error that
        // normally includes a table/field name in the message.
        JdbAggregate::configure(array('detailed_errors' => false));
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        JdbAggregate::aggregateRelated('order_lines', 1, 'sum', '../../traversal');

        $err = JdbAggregate::getLastError();
        $this->assertNotNull($err);
        // The sanitised message must not expose the bad field name.
        $this->assertStringNotContainsString('traversal', $err['message']);
        // Structural keys must still be present.
        $this->assertArrayHasKey('method',  $err);
        $this->assertArrayHasKey('message', $err);
        $this->assertArrayHasKey('time',    $err);
    }

    /**
     * @brief clearError() is idempotent: calling it when no error is stored
     *        leaves getLastError() returning null.
     *
     * @covers JdbAggregate::clearError
     * @covers JdbAggregate::getLastError
     * @group  errorHandling
     *
     * @return void
     */
    public function testClearErrorIsIdempotentWhenAlreadyClear()
    {
        $this->assertNull(JdbAggregate::getLastError());
        JdbAggregate::clearError(); // called when no error is stored
        $this->assertNull(JdbAggregate::getLastError());
    }

    /**
     * @brief The last error state is sticky: a subsequent successful operation does NOT
     *        automatically clear a previously recorded error.
     *        Only an explicit clearError() (or resetAll()) resets it.
     *
     * @covers JdbAggregate::clearError
     * @covers JdbAggregate::getLastError
     * @group  errorHandling
     *
     * @return void
     */
    public function testLastErrorPersistsUntilExplicitlyClearedAfterSuccess()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');

        // Trigger a failure.
        JdbAggregate::getRelated('nonexistent_relation', 1);
        $errorAfterFailure = JdbAggregate::getLastError();
        $this->assertNotNull($errorAfterFailure);

        // A successful operation does NOT wipe the stored error.
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'OK'), 'order_lines', array());
        $this->assertIsArray($res);
        $this->assertNotNull(JdbAggregate::getLastError(),
            'Error must remain set until clearError() is called explicitly');

        // Only clearError() resets it.
        JdbAggregate::clearError();
        $this->assertNull(JdbAggregate::getLastError());
    }

    // =========================================================================
    // 23. insertWithChildren – additional type / table validation
    // =========================================================================

    /**
     * @brief insertWithChildren() returns false and sets an error when parentTable contains
     *        a path-traversal sequence such as "../bad".
     *
     * @covers JdbAggregate::insertWithChildren
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testInsertWithChildrenReturnsFalseForPathTraversalInParentTable()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $result = JdbAggregate::insertWithChildren('../bad', array('ref' => 'X'), 'order_lines', array());
        $this->assertFalse($result);
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief insertWithChildren() returns false and sets an error when $relationName refers
     *        to a MANY_TO_MANY relation (only ONE_TO_MANY and TEMPORAL are accepted).
     *
     * @covers JdbAggregate::insertWithChildren
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testInsertWithChildrenReturnsFalseForManyToManyRelation()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $result = JdbAggregate::insertWithChildren('documents', array('title' => 'X'), 'doc_tags', array());
        $this->assertFalse($result);
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief The result array from insertWithChildren() always contains both 'parent_id'
     *        and 'child_ids' keys, even when multiple children are inserted.
     *
     * @covers JdbAggregate::insertWithChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testInsertWithChildrenResultHasRequiredKeys()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'KEYS'), 'order_lines', array(
            array('amount' => 1), array('amount' => 2), array('amount' => 3),
        ));
        $this->assertArrayHasKey('parent_id',  $res);
        $this->assertArrayHasKey('child_ids',  $res);
        $this->assertCount(3, $res['child_ids']);
    }

    // =========================================================================
    // 24. loadWith – additional coverage
    // =========================================================================

    /**
     * @brief loadWith() with an empty relation list returns the parent record with
     *        an empty 'relations' array (not null, not false).
     *
     * @covers JdbAggregate::loadWith
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithEmptyRelationListReturnsRecordWithEmptyRelations()
    {
        $orderId = $this->_ins('orders', array('ref' => 'ALONE'));
        $loaded  = JdbAggregate::loadWith('orders', $orderId, array());

        $this->assertIsArray($loaded);
        $this->assertArrayHasKey('record',    $loaded);
        $this->assertArrayHasKey('relations', $loaded);
        $this->assertSame(array(), $loaded['relations']);
        $this->assertSame('ALONE', $loaded['record']['ref']);
    }

    /**
     * @brief loadWith() works correctly with a MANY_TO_MANY relation in the list.
     *
     * @covers JdbAggregate::loadWith
     * @group  manyToMany
     *
     * @return void
     */
    public function testLoadWithIncludesManyToManyRelation()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId  = $this->_ins('documents', array('title' => 'LW-M2M'));
        $tag1   = $this->_ins('tags', array('name' => 'alpha'));
        $tag2   = $this->_ins('tags', array('name' => 'beta'));
        JdbAggregate::attach('doc_tags', $docId, $tag1);
        JdbAggregate::attach('doc_tags', $docId, $tag2);

        $loaded = JdbAggregate::loadWith('documents', $docId, array('doc_tags'));
        $this->assertSame('LW-M2M', $loaded['record']['title']);
        $this->assertCount(2, $loaded['relations']['doc_tags']);
    }

    /**
     * @brief loadWith() returns false and sets an error when $parentId is null.
     *
     * @covers JdbAggregate::loadWith
     * @covers JdbAggregate::getLastError
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithReturnsFalseForNullParentId()
    {
        $this->assertFalse(JdbAggregate::loadWith('orders', null, array()));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief loadWith() with a per-relation limit of 0 returns ALL children for that
     *        relation (0 is the sentinel value meaning "no limit").
     *
     * @covers JdbAggregate::loadWith
     * @group  oneToMany
     *
     * @return void
     */
    public function testLoadWithZeroLimitMeansNoLimit()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'NOLIM'), 'order_lines', array(
            array('amount' => 1), array('amount' => 2),
            array('amount' => 3), array('amount' => 4),
            array('amount' => 5),
        ));
        $loaded = JdbAggregate::loadWith(
            'orders', $res['parent_id'],
            array('order_lines'),
            array('order_lines' => 0)   // 0 = no limit
        );
        $this->assertCount(5, $loaded['relations']['order_lines']);
    }

    // =========================================================================
    // 25. MANY_TO_MANY – additional boundary paths
    // =========================================================================

    /**
     * @brief attach() returns false and sets an error when parentId is null.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::getLastError
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachReturnsFalseForNullParentId()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $this->assertFalse(JdbAggregate::attach('doc_tags', null, 1));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief attach() returns false and sets an error when childId is null.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::getLastError
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachReturnsFalseForNullChildId()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $this->assertFalse(JdbAggregate::attach('doc_tags', 1, null));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief deleteWithChildren() on a MANY_TO_MANY relation with no junction rows succeeds
     *        and reports deleted=0.
     *
     * @covers JdbAggregate::deleteWithChildren
     * @group  manyToMany
     *
     * @return void
     */
    public function testDeleteWithChildrenManyToManyWhenNothingAttachedReturnsZero()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Empty Doc'));

        $del = JdbAggregate::deleteWithChildren('doc_tags', $docId);
        $this->assertIsArray($del);
        $this->assertSame(0, $del['deleted']);
    }

    /**
     * @brief minRelated() and maxRelated() work correctly on a MANY_TO_MANY relation.
     *
     * @covers JdbAggregate::minRelated
     * @covers JdbAggregate::maxRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testMinAndMaxRelatedManyToMany()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'MinMax Doc'));
        $tag1  = $this->_ins('tags', array('name' => 'p', 'score' => 5));
        $tag2  = $this->_ins('tags', array('name' => 'q', 'score' => 15));
        $tag3  = $this->_ins('tags', array('name' => 'r', 'score' => 10));
        JdbAggregate::attach('doc_tags', $docId, $tag1);
        JdbAggregate::attach('doc_tags', $docId, $tag2);
        JdbAggregate::attach('doc_tags', $docId, $tag3);

        $this->assertEqualsWithDelta(5.0,
            JdbAggregate::minRelated('doc_tags', $docId, 'score'), 0.001);
        $this->assertEqualsWithDelta(15.0,
            JdbAggregate::maxRelated('doc_tags', $docId, 'score'), 0.001);
    }

    /**
     * @brief aggregateRelated('count') on a MANY_TO_MANY relation returns 0 (not null or false)
     *        when no junction rows exist for the parent.
     *
     * @covers JdbAggregate::aggregateRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testAggregateRelatedCountManyToManyReturnsZeroWhenNothingAttached()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Zero Count'));
        $this->assertSame(0, JdbAggregate::aggregateRelated('doc_tags', $docId, 'count', 'score'));
    }

    /**
     * @brief attach() returns false and sets an error when a payload key is not in the
     *        relation's declared payloadFields list.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::getLastError
     * @group  manyToMany
     *
     * @return void
     */
    public function testAttachRejectsUndeclaredPayloadField()
    {
        $this->_defM2M(
            'doc_tags', 'documents', 'tags', 'doc_tag_pivot',
            'document_id', 'tag_id',
            array('weight')   // only 'weight' is declared
        );
        $docId = $this->_ins('documents', array('title' => 'Doc'));
        $tagId = $this->_ins('tags', array('name'  => 'tag'));

        // 'notes' is not in payloadFields → must be rejected.
        $result = JdbAggregate::attach('doc_tags', $docId, $tagId, array('notes' => 'extra'));
        $this->assertFalse($result);
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief detach() removes only the specific (parent, child) pair from the junction table,
     *        leaving all other pairs for the same parent intact.
     *
     * @covers JdbAggregate::detach
     * @covers JdbAggregate::getRelated
     * @covers JdbAggregate::countRelated
     * @group  manyToMany
     *
     * @return void
     */
    public function testDetachRemovesOnlySpecificPairNotAllJunctionRowsForParent()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Doc'));
        $tag1  = $this->_ins('tags', array('name' => 'keep'));
        $tag2  = $this->_ins('tags', array('name' => 'remove'));

        JdbAggregate::attach('doc_tags', $docId, $tag1);
        JdbAggregate::attach('doc_tags', $docId, $tag2);

        // Detach only tag2.
        JdbAggregate::detach('doc_tags', $docId, $tag2);

        // tag1 must still be related; total count must be 1.
        $related = JdbAggregate::getRelated('doc_tags', $docId);
        $this->assertCount(1, $related);
        $this->assertSame('keep', $related[0]['name']);
    }

    /**
     * @brief detach() returns false with a 'Parent record does not exist' error when
     *        enforce_foreign_keys=true and the parent record is missing.
     *
     * @covers JdbAggregate::detach
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  foreignKeyEnforcement
     *
     * @return void
     */
    public function testDetachReturnsFalseWhenParentMissingAndFkEnforced()
    {
        JdbAggregate::configure(['enforce_foreign_keys' => true]);
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $tagId = $this->_ins('tags', ['name' => 'orphan']);

        $result = JdbAggregate::detach('doc_tags', 99999, $tagId);
        $this->assertFalse($result);
        $this->assertStringContainsString('Parent record does not exist', JdbAggregate::getLastError()['message']);
    }

    /**
     * @brief detach() returns false with a 'Child record does not exist' error when
     *        enforce_foreign_keys=true and the child record is missing.
     *
     * @covers JdbAggregate::detach
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  foreignKeyEnforcement
     *
     * @return void
     */
    public function testDetachReturnsFalseWhenChildMissingAndFkEnforced()
    {
        JdbAggregate::configure(['enforce_foreign_keys' => true]);
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', ['title' => 'Real Doc']);

        $result = JdbAggregate::detach('doc_tags', $docId, 99999);
        $this->assertFalse($result);
        $this->assertStringContainsString('Child record does not exist', JdbAggregate::getLastError()['message']);
    }

    // =========================================================================
    // 26. POLYMORPHIC – additional coverage
    // =========================================================================

    /**
     * @brief getPolymorphic() returns false and sets an error when the relation
     *        name is not registered.
     *
     * @covers JdbAggregate::getPolymorphic
     * @covers JdbAggregate::getLastError
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicReturnsFalseForUnknownRelation()
    {
        $this->assertFalse(JdbAggregate::getPolymorphic('no_such_relation', 'orders', 1));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief getPolymorphic() respects the $limit argument and returns at most $limit records.
     *
     * @covers JdbAggregate::getPolymorphic
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicRespectsLimit()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $orderId = $this->_ins('orders', array('ref' => 'LIM-POLY'));
        for ($i = 0; $i < 5; $i++) {
            $this->_ins('media', array(
                'mediable_type' => 'orders',
                'mediable_id'   => (string)$orderId,
                'url'           => 'file' . $i . '.pdf',
            ));
        }

        $limited = JdbAggregate::getPolymorphic('media', 'orders', $orderId, array(), 3);
        $this->assertIsArray($limited);
        $this->assertCount(3, $limited);
    }

    /**
     * @brief getPolymorphic() applies extra $conditions and returns only matching rows.
     *
     * @covers JdbAggregate::getPolymorphic
     * @group  polymorphic
     *
     * @return void
     */
    public function testGetPolymorphicFiltersWithConditions()
    {
        JdbAggregate::defineRelation('media', JdbRelationMeta::polymorphic(
            'media', 'mediable_type', 'mediable_id'
        ));
        $orderId = $this->_ins('orders', array('ref' => 'COND-POLY'));
        $this->_ins('media', array('mediable_type' => 'orders', 'mediable_id' => (string)$orderId, 'mime' => 'pdf', 'url' => 'a.pdf'));
        $this->_ins('media', array('mediable_type' => 'orders', 'mediable_id' => (string)$orderId, 'mime' => 'img', 'url' => 'b.png'));
        $this->_ins('media', array('mediable_type' => 'orders', 'mediable_id' => (string)$orderId, 'mime' => 'pdf', 'url' => 'c.pdf'));

        $pdfs = JdbAggregate::getPolymorphic('media', 'orders', $orderId, array('mime' => 'pdf'));
        $this->assertIsArray($pdfs);
        $this->assertCount(2, $pdfs);
        foreach ($pdfs as $row) {
            $this->assertSame('pdf', $row['mime']);
        }
    }

    // =========================================================================
    // 27. TEMPORAL – additional coverage
    // =========================================================================

    /**
     * @brief countRelated() works correctly on TEMPORAL relations; it returns the total row
     *        count including archived rows because countRelated does not filter by cutoff.
     *
     * @covers JdbAggregate::countRelated
     * @group  temporal
     *
     * @return void
     */
    public function testCountRelatedWorksOnTemporalRelation()
    {
        $cutoff = $this->_cutoff();
        $this->_defTemporal($cutoff);

        $entityId = $this->_ins('entities', array('name' => 'CNT-TEMP'));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff - 100));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff + 100));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff + 200));

        // countRelated uses forEach on the child table without archive filtering,
        // so all three rows must be counted.
        $this->assertSame(3, JdbAggregate::countRelated('entity_events', $entityId));
    }

    /**
     * @brief sumRelated() works correctly on TEMPORAL relations.
     *
     * @covers JdbAggregate::sumRelated
     * @group  temporal
     *
     * @return void
     */
    public function testSumRelatedWorksOnTemporalRelation()
    {
        $this->_defTemporal(0); // no archive cutoff

        $entityId = $this->_ins('entities', array('name' => 'SUM-TEMP'));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => 1000, 'value' => 10));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => 2000, 'value' => 30));

        $this->assertEqualsWithDelta(40.0,
            JdbAggregate::sumRelated('entity_events', $entityId, 'value'), 0.001);
    }

    /**
     * @brief getTemporalRange() returns false and sets an error for an unknown relation name.
     *
     * @covers JdbAggregate::getTemporalRange
     * @covers JdbAggregate::getLastError
     * @group  temporal
     *
     * @return void
     */
    public function testGetTemporalRangeReturnsFalseForUnknownRelation()
    {
        $this->assertFalse(JdbAggregate::getTemporalRange('no_such_temporal', 1, 0, 0));
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief The result array from syncChildren() always contains all three keys
     *        (inserted, deleted, skipped) regardless of the number of rows involved.
     *
     * @covers JdbAggregate::syncChildren
     * @group  oneToMany
     *
     * @return void
     */
    public function testSyncChildrenResultAlwaysContainsAllThreeKeys()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $res  = JdbAggregate::insertWithChildren('orders', array('ref' => '3KEYS'), 'order_lines', array());
        $sync = JdbAggregate::syncChildren('order_lines', $res['parent_id'], array());

        $this->assertArrayHasKey('inserted', $sync);
        $this->assertArrayHasKey('deleted',  $sync);
        $this->assertArrayHasKey('skipped',  $sync);
    }

    /**
     * @brief getRelated() works correctly on TEMPORAL relations: all returned rows are
     *        annotated with the '_archived' key when an archive cutoff is configured.
     *
     * @covers JdbAggregate::getRelated
     * @group  temporal
     *
     * @return void
     */
    public function testGetRelatedWorksOnTemporalRelation()
    {
        $cutoff = $this->_cutoff();
        $this->_defTemporal($cutoff);

        $entityId = $this->_ins('entities', array('name' => 'GR-TEMP'));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff - 50));
        $this->_ins('events', array('entity_id' => (string)$entityId, 'occurred_at' => $cutoff + 50));

        $rows = JdbAggregate::getRelated('entity_events', $entityId);
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);

        // Both rows must carry the '_archived' annotation.
        foreach ($rows as $row) {
            $this->assertArrayHasKey('_archived', $row);
        }
    }

    // =========================================================================
    // 28. enforce_foreign_keys configuration
    // =========================================================================

    /**
     * @brief syncChildren() returns false and sets an error when enforce_foreign_keys=true
     *        and the parent record does not exist in the parent table.
     *
     * @covers JdbAggregate::syncChildren
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  foreignKeyEnforcement
     *
     * @return void
     */
    public function testSyncChildrenReturnsFalseWhenParentMissingAndFkEnforced()
    {
        JdbAggregate::configure(array('enforce_foreign_keys' => true));
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');

        // Use an ID that was never inserted → parent does not exist.
        $result = JdbAggregate::syncChildren('order_lines', 99999, array(array('amount' => 1)));
        $this->assertFalse($result);
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief attach() returns false and sets an error when enforce_foreign_keys=true
     *        and the parent record does not exist.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  foreignKeyEnforcement
     *
     * @return void
     */
    public function testAttachReturnsFalseWhenParentMissingAndFkEnforced()
    {
        JdbAggregate::configure(array('enforce_foreign_keys' => true));
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');

        // Insert the tag but NOT the document.
        $tagId = $this->_ins('tags', array('name' => 'orphan-tag'));

        $result = JdbAggregate::attach('doc_tags', 99999, $tagId);
        $this->assertFalse($result);
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief attach() returns false and sets an error when enforce_foreign_keys=true
     *        and the child record does not exist.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::configure
     * @covers JdbAggregate::getLastError
     * @group  foreignKeyEnforcement
     *
     * @return void
     */
    public function testAttachReturnsFalseWhenChildMissingAndFkEnforced()
    {
        JdbAggregate::configure(array('enforce_foreign_keys' => true));
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');

        $docId = $this->_ins('documents', array('title' => 'Real Doc'));

        // tag ID 99999 does not exist.
        $result = JdbAggregate::attach('doc_tags', $docId, 99999);
        $this->assertFalse($result);
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    // =========================================================================
    // 29. Relation registry edge cases
    // =========================================================================

    /**
     * @brief defineRelation() returns false and sets an error when the name contains only
     *        whitespace (equivalent to an empty name after trim()).
     *
     * @covers JdbAggregate::defineRelation
     * @covers JdbAggregate::getLastError
     * @group  configuration
     *
     * @return void
     */
    public function testDefineRelationWithWhitespaceOnlyNameReturnsFalse()
    {
        $this->assertFalse(
            JdbAggregate::defineRelation('   ', JdbRelationMeta::oneToMany('a', 'b', 'fk'))
        );
        $this->assertNotNull(JdbAggregate::getLastError());
    }

    /**
     * @brief removeRelation() called on a name that was never registered is a no-op:
     *        hasRelation() still returns false afterwards.
     *
     * @covers JdbAggregate::removeRelation
     * @covers JdbAggregate::hasRelation
     * @group  configuration
     *
     * @return void
     */
    public function testRemoveRelationOnUnregisteredNameIsNoOp()
    {
        JdbAggregate::removeRelation('never_existed');
        $this->assertFalse(JdbAggregate::hasRelation('never_existed'));
    }

    /**
     * @brief hasRelation() returns false for all previously registered relations
     *        after clearRelations() is called.
     *
     * @covers JdbAggregate::clearRelations
     * @covers JdbAggregate::hasRelation
     * @group  configuration
     *
     * @return void
     */
    public function testHasRelationReturnsFalseForAllRelationsAfterClearRelations()
    {
        $this->_defO2M('rel_x', 'a', 'b', 'fk');
        $this->_defO2M('rel_y', 'c', 'd', 'fk');
        $this->assertTrue(JdbAggregate::hasRelation('rel_x'));
        $this->assertTrue(JdbAggregate::hasRelation('rel_y'));

        JdbAggregate::clearRelations();

        $this->assertFalse(JdbAggregate::hasRelation('rel_x'));
        $this->assertFalse(JdbAggregate::hasRelation('rel_y'));
    }

    /**
     * @brief getRelationMeta() returns null for every previously registered relation
     *        after clearRelations() is called, confirming no stale metadata remains.
     *
     * @covers JdbAggregate::clearRelations
     * @covers JdbAggregate::getRelationMeta
     * @group  configuration
     *
     * @return void
     */
    public function testGetRelationMetaReturnsNullAfterClearRelations()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $this->assertNotNull(JdbAggregate::getRelationMeta('order_lines'));

        JdbAggregate::clearRelations();

        $this->assertNull(JdbAggregate::getRelationMeta('order_lines'));
    }

    // =========================================================================
    // 30. Cross-cutting / regression
    // =========================================================================

    /**
     * @brief Defining a relation, removing it, then redefining it with new metadata
     *        stores the new metadata with no stale cache from the old definition.
     *
     * @covers JdbAggregate::defineRelation
     * @covers JdbAggregate::removeRelation
     * @covers JdbAggregate::hasRelation
     * @covers JdbAggregate::getRelationMeta
     * @group  regression
     *
     * @return void
     */
    public function testRemoveAndRedefineRelationUpdatesMetadata()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        JdbAggregate::removeRelation('order_lines');
        $this->assertFalse(JdbAggregate::hasRelation('order_lines'));

        // Redefine with a different FK name.
        $this->_defO2M('order_lines', 'orders', 'lines', 'o_id');

        $this->assertTrue(JdbAggregate::hasRelation('order_lines'));
        $this->assertSame('o_id', JdbAggregate::getRelationMeta('order_lines')->fkField);
    }

    /**
     * @brief Multiple isolated parents with children must not interfere with each other's
     *        aggregate values (cross-parent isolation for all aggregate functions).
     *
     * @covers JdbAggregate::countRelated
     * @covers JdbAggregate::sumRelated
     * @covers JdbAggregate::avgRelated
     * @covers JdbAggregate::minRelated
     * @covers JdbAggregate::maxRelated
     * @group  regression
     *
     * @return void
     */
    public function testAggregatesAreFullyIsolatedBetweenParents()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');
        $resA = JdbAggregate::insertWithChildren('orders', array('ref' => 'ISO-A'), 'order_lines', array(
            array('amount' => 1), array('amount' => 2),
        ));
        $resB = JdbAggregate::insertWithChildren('orders', array('ref' => 'ISO-B'), 'order_lines', array(
            array('amount' => 100), array('amount' => 200), array('amount' => 300),
        ));

        $pidA = $resA['parent_id'];
        $pidB = $resB['parent_id'];

        $this->assertSame(2, JdbAggregate::countRelated('order_lines', $pidA));
        $this->assertSame(3, JdbAggregate::countRelated('order_lines', $pidB));

        $this->assertEqualsWithDelta(3.0,   JdbAggregate::sumRelated('order_lines', $pidA, 'amount'), 0.001);
        $this->assertEqualsWithDelta(600.0, JdbAggregate::sumRelated('order_lines', $pidB, 'amount'), 0.001);

        $this->assertEqualsWithDelta(1.5,   JdbAggregate::avgRelated('order_lines', $pidA, 'amount'), 0.001);
        $this->assertEqualsWithDelta(200.0, JdbAggregate::avgRelated('order_lines', $pidB, 'amount'), 0.001);

        $this->assertEqualsWithDelta(1.0,   JdbAggregate::minRelated('order_lines', $pidA, 'amount'), 0.001);
        $this->assertEqualsWithDelta(100.0, JdbAggregate::minRelated('order_lines', $pidB, 'amount'), 0.001);

        $this->assertEqualsWithDelta(2.0,   JdbAggregate::maxRelated('order_lines', $pidA, 'amount'), 0.001);
        $this->assertEqualsWithDelta(300.0, JdbAggregate::maxRelated('order_lines', $pidB, 'amount'), 0.001);
    }

    /**
     * @brief A full insert → getRelated → syncChildren → getRelated cycle leaves the data
     *        in a consistent state with the correct final child set and aggregate values.
     *
     * @covers JdbAggregate::insertWithChildren
     * @covers JdbAggregate::countRelated
     * @covers JdbAggregate::syncChildren
     * @covers JdbAggregate::getRelated
     * @covers JdbAggregate::sumRelated
     * @group  regression
     *
     * @return void
     */
    public function testFullInsertSyncCycleProducesConsistentState()
    {
        $this->_defO2M('order_lines', 'orders', 'lines', 'order_id');

        // Step 1: create parent with 3 children.
        $res = JdbAggregate::insertWithChildren('orders', array('ref' => 'CYCLE'), 'order_lines', array(
            array('amount' => 10, 'sku' => 'A'),
            array('amount' => 20, 'sku' => 'B'),
            array('amount' => 30, 'sku' => 'C'),
        ));
        $pid = $res['parent_id'];
        $this->assertSame(3, JdbAggregate::countRelated('order_lines', $pid));

        // Step 2: sync to just 2 new children.
        $sync = JdbAggregate::syncChildren('order_lines', $pid, array(
            array('amount' => 50, 'sku' => 'D'),
            array('amount' => 60, 'sku' => 'E'),
        ));
        $this->assertSame(2, $sync['inserted']);
        $this->assertSame(3, $sync['deleted']);

        // Step 3: verify final state.
        $children = JdbAggregate::getRelated('order_lines', $pid);
        $this->assertCount(2, $children);
        $skus = array_column($children, 'sku');
        sort($skus);
        $this->assertSame(array('D', 'E'), $skus);

        $this->assertEqualsWithDelta(110.0,
            JdbAggregate::sumRelated('order_lines', $pid, 'amount'), 0.001);
    }

    /**
     * @brief A full M2M attach → detach → re-attach cycle leaves exactly one junction row
     *        and countRelated reflects each state correctly.
     *
     * @covers JdbAggregate::attach
     * @covers JdbAggregate::detach
     * @covers JdbAggregate::countRelated
     * @covers JdbAggregate::getRelated
     * @group  regression
     *
     * @return void
     */
    public function testManyToManyAttachDetachReAttachCycle()
    {
        $this->_defM2M('doc_tags', 'documents', 'tags', 'doc_tag_pivot', 'document_id', 'tag_id');
        $docId = $this->_ins('documents', array('title' => 'Cycle Doc'));
        $tagId = $this->_ins('tags',      array('name'  => 'cycle-tag'));

        // Attach.
        JdbAggregate::attach('doc_tags', $docId, $tagId);
        $this->assertSame(1, JdbAggregate::countRelated('doc_tags', $docId));

        // Detach.
        JdbAggregate::detach('doc_tags', $docId, $tagId);
        $this->assertSame(0, JdbAggregate::countRelated('doc_tags', $docId));
        $this->assertNull(JdbAggregate::getRelated('doc_tags', $docId));

        // Re-attach.
        JdbAggregate::attach('doc_tags', $docId, $tagId);
        $this->assertSame(1, JdbAggregate::countRelated('doc_tags', $docId));
        $related = JdbAggregate::getRelated('doc_tags', $docId);
        $this->assertCount(1, $related);
        $this->assertSame('cycle-tag', $related[0]['name']);
    }

}
