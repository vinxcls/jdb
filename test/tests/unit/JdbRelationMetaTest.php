<?php
use Codeception\Test\Unit;

/**
 * JdbRelationMetaTest – pure unit tests for the JdbRelationMeta value object.
 *
 * No filesystem, no JdbManager, no JdbTrace access.
 * Tests factory methods and involvedTables() only.
 */
class JdbRelationMetaTest extends Unit
{
    // =========================================================================
    // oneToMany
    // =========================================================================

    public function testOneToManyHasCorrectType()
    {
        $this->assertSame(JdbRelationType::ONE_TO_MANY,
            JdbRelationMeta::oneToMany('parents', 'children', 'parent_id')->type);
    }

    public function testOneToManySetsAllFields()
    {
        $m = JdbRelationMeta::oneToMany('orders', 'lines', 'order_id');
        $this->assertSame('orders',   $m->parentTable);
        $this->assertSame('lines',    $m->childTable);
        $this->assertSame('order_id', $m->fkField);
    }

    public function testOneToManyPayloadFieldsDefaultsToEmptyArray()
    {
        $m = JdbRelationMeta::oneToMany('a', 'b', 'fk');
        $this->assertIsArray($m->payloadFields);
        $this->assertEmpty($m->payloadFields);
    }

    public function testOneToManyJunctionFieldsAreNull()
    {
        $m = JdbRelationMeta::oneToMany('a', 'b', 'fk');
        $this->assertNull($m->junctionTable);
        $this->assertNull($m->leftFk);
        $this->assertNull($m->rightFk);
        $this->assertNull($m->typeField);
        $this->assertNull($m->idField);
        $this->assertNull($m->timestampField);
        $this->assertNull($m->archiveCutoff);
    }

    // =========================================================================
    // manyToMany
    // =========================================================================

    public function testManyToManyHasCorrectType()
    {
        $this->assertSame(JdbRelationType::MANY_TO_MANY,
            JdbRelationMeta::manyToMany('a', 'b', 'ab', 'a_id', 'b_id')->type);
    }

    public function testManyToManySetsCoreFields()
    {
        $m = JdbRelationMeta::manyToMany('users', 'roles', 'user_roles', 'user_id', 'role_id');
        $this->assertSame('users',      $m->parentTable);
        $this->assertSame('roles',      $m->childTable);
        $this->assertSame('user_roles', $m->junctionTable);
        $this->assertSame('user_id',    $m->leftFk);
        $this->assertSame('role_id',    $m->rightFk);
    }

    public function testManyToManyPayloadFieldsStored()
    {
        $p = array('granted_at', 'granted_by');
        $this->assertSame($p,
            JdbRelationMeta::manyToMany('a', 'b', 'ab', 'a_id', 'b_id', $p)->payloadFields);
    }

    public function testManyToManyPayloadFieldsDefaultsToEmpty()
    {
        $this->assertEmpty(
            JdbRelationMeta::manyToMany('a', 'b', 'ab', 'a_id', 'b_id')->payloadFields);
    }

    // =========================================================================
    // polymorphic
    // =========================================================================

    public function testPolymorphicHasCorrectType()
    {
        $this->assertSame(JdbRelationType::POLYMORPHIC,
            JdbRelationMeta::polymorphic('media', 'mediable_type', 'mediable_id')->type);
    }

    public function testPolymorphicParentTableIsNull()
    {
        $this->assertNull(
            JdbRelationMeta::polymorphic('media', 'mt', 'mid')->parentTable);
    }

    public function testPolymorphicSetsDynamicFields()
    {
        $m = JdbRelationMeta::polymorphic('media', 'mediable_type', 'mediable_id');
        $this->assertSame('media',         $m->childTable);
        $this->assertSame('mediable_type', $m->typeField);
        $this->assertSame('mediable_id',   $m->idField);
    }

    // =========================================================================
    // temporal
    // =========================================================================

    public function testTemporalHasCorrectType()
    {
        $this->assertSame(JdbRelationType::TEMPORAL,
            JdbRelationMeta::temporal('a', 'b', 'fk', 'ts')->type);
    }

    public function testTemporalSetsAllFields()
    {
        $cutoff = 1704067200;
        $m = JdbRelationMeta::temporal('entities', 'events', 'entity_id', 'occurred_at', $cutoff);
        $this->assertSame('entities',    $m->parentTable);
        $this->assertSame('events',      $m->childTable);
        $this->assertSame('entity_id',   $m->fkField);
        $this->assertSame('occurred_at', $m->timestampField);
        $this->assertSame($cutoff,       $m->archiveCutoff);
    }

    public function testTemporalArchiveCutoffDefaultsToZero()
    {
        $this->assertSame(0,
            JdbRelationMeta::temporal('a', 'b', 'fk', 'ts')->archiveCutoff);
    }

    // =========================================================================
    // involvedTables()
    // =========================================================================

    public function testInvolvedTablesOneToMany()
    {
        $t = JdbRelationMeta::oneToMany('parents', 'children', 'fk')->involvedTables();
        $this->assertCount(2, $t);
        $this->assertContains('parents',  $t);
        $this->assertContains('children', $t);
    }

    public function testInvolvedTablesManyToMany()
    {
        $t = JdbRelationMeta::manyToMany('a', 'b', 'ab', 'a_id', 'b_id')->involvedTables();
        $this->assertCount(3, $t);
        $this->assertContains('a',  $t);
        $this->assertContains('b',  $t);
        $this->assertContains('ab', $t);
    }

    public function testInvolvedTablesPolymorphicReturnsOnlyChild()
    {
        $t = JdbRelationMeta::polymorphic('media', 'mt', 'mid')->involvedTables();
        $this->assertCount(1, $t);
        $this->assertContains('media', $t);
    }

    public function testInvolvedTablesTemporal()
    {
        $t = JdbRelationMeta::temporal('entities', 'events', 'fk', 'ts')->involvedTables();
        $this->assertCount(2, $t);
        $this->assertContains('entities', $t);
        $this->assertContains('events',   $t);
    }

    public function testInvolvedTablesDeduplicatesSameName()
    {
        $t = JdbRelationMeta::oneToMany('logs', 'logs', 'parent_id')->involvedTables();
        $this->assertCount(1, $t);
        $this->assertSame(array('logs'), $t);
    }
}
