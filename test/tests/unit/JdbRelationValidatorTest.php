<?php
use Codeception\Test\Unit;

/**
 * JdbRelationValidatorTest – tests JdbRelationValidator with the real JdbErrorHandler.
 *
 * Assertions focus on return values (the public contract).
 * We verify that JdbErrorHandler::hasStackError() is true after failures — this uses
 * the real JdbErrorHandler API from jdb.php (hasStackError() / get() / format()).
 */
class JdbRelationValidatorTest extends Unit
{
    protected function _before()
    {
        JdbErrorHandler::resetAll();
    }

    // =========================================================================
    // ONE_TO_MANY
    // =========================================================================

    public function testOneToManyValidReturnsTrue()
    {
        $this->assertTrue(
            JdbRelationValidator::validate(JdbRelationMeta::oneToMany('orders', 'lines', 'order_id'))
        );
        $this->assertFalse(JdbErrorHandler::hasStackError());
    }

    public function testOneToManyMissingParentTableReturnsFalseAndPushesTrace()
    {
        $m = JdbRelationMeta::oneToMany('orders', 'lines', 'order_id');
        $m->parentTable = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
        $this->assertTrue(JdbErrorHandler::hasStackError());
    }

    public function testOneToManyMissingChildTableReturnsFalse()
    {
        $m = JdbRelationMeta::oneToMany('orders', 'lines', 'order_id');
        $m->childTable = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    public function testOneToManyMissingFkFieldReturnsFalse()
    {
        $m = JdbRelationMeta::oneToMany('orders', 'lines', 'order_id');
        $m->fkField = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
        $this->assertTrue(JdbErrorHandler::hasStackError());
    }

    public function testOneToManyInvalidTableNameReturnsFalse()
    {
        $m = JdbRelationMeta::oneToMany('orders', 'lines', 'order_id');
        $m->parentTable = '../../etc/passwd';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    // =========================================================================
    // MANY_TO_MANY
    // =========================================================================

    public function testManyToManyValidReturnsTrue()
    {
        $this->assertTrue(
            JdbRelationValidator::validate(
                JdbRelationMeta::manyToMany('u', 'r', 'ur', 'u_id', 'r_id')
            )
        );
    }

    public function testManyToManyMissingJunctionTableReturnsFalse()
    {
        $m = JdbRelationMeta::manyToMany('u', 'r', 'ur', 'u_id', 'r_id');
        $m->junctionTable = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    public function testManyToManyMissingLeftFkReturnsFalse()
    {
        $m = JdbRelationMeta::manyToMany('u', 'r', 'ur', 'u_id', 'r_id');
        $m->leftFk = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    public function testManyToManyMissingRightFkReturnsFalse()
    {
        $m = JdbRelationMeta::manyToMany('u', 'r', 'ur', 'u_id', 'r_id');
        $m->rightFk = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    // =========================================================================
    // POLYMORPHIC
    // =========================================================================

    public function testPolymorphicValidReturnsTrue()
    {
        $this->assertTrue(
            JdbRelationValidator::validate(
                JdbRelationMeta::polymorphic('media', 'mediable_type', 'mediable_id')
            )
        );
    }

    public function testPolymorphicMissingChildTableReturnsFalse()
    {
        $m = JdbRelationMeta::polymorphic('media', 'mt', 'mid');
        $m->childTable = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    public function testPolymorphicMissingTypeFieldReturnsFalse()
    {
        $m = JdbRelationMeta::polymorphic('media', 'mt', 'mid');
        $m->typeField = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    public function testPolymorphicMissingIdFieldReturnsFalse()
    {
        $m = JdbRelationMeta::polymorphic('media', 'mt', 'mid');
        $m->idField = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    // =========================================================================
    // TEMPORAL
    // =========================================================================

    public function testTemporalValidReturnsTrue()
    {
        $this->assertTrue(
            JdbRelationValidator::validate(
                JdbRelationMeta::temporal('entities', 'events', 'entity_id', 'occurred_at')
            )
        );
    }

    public function testTemporalMissingTimestampFieldReturnsFalse()
    {
        $m = JdbRelationMeta::temporal('e', 'v', 'fk', 'ts');
        $m->timestampField = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    public function testTemporalMissingFkFieldReturnsFalse()
    {
        $m = JdbRelationMeta::temporal('e', 'v', 'fk', 'ts');
        $m->fkField = '';
        $this->assertFalse(JdbRelationValidator::validate($m));
    }

    // =========================================================================
    // Unknown type
    // =========================================================================

    public function testUnknownTypeReturnsFalseAndPushesTrace()
    {
        $m = JdbRelationMeta::oneToMany('a', 'b', 'fk');
        $m->type = 'bad_type';
        $this->assertFalse(JdbRelationValidator::validate($m));
        $this->assertTrue(JdbErrorHandler::hasStackError());
    }
}
