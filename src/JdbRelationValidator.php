<?php
require_once __DIR__ . "/JdbErrorHandler.php";
require_once __DIR__ . "/JdbUtil.php";
require_once __DIR__ . "/JdbRelationType.php";

/**
 * JdbRelationValidator – Validates JdbRelationMeta objects and raw identifiers.
 *
 * All public methods are static.  On failure, one or more frames are pushed
 * onto the shared JdbErrorHandler stack before returning false, exactly as every
 * other JDB internal component does.
 *
 * Separation of concerns: validation logic lives here so that JdbAggregate
 * can stay focused on orchestration and JdbRelationMeta can remain a pure
 * data object without embedded assertions.
 */
class JdbRelationValidator
{
    /**
     * Validates the structural completeness of a JdbRelationMeta object.
     *
     * Does NOT query the filesystem (tables need not exist yet).
     * Checks that all required fields for the declared type are present and
     * syntactically valid (length, charset, path-traversal safety).
     *
     * @param  JdbRelationMeta $meta  Relation descriptor to validate
     * @return bool  True if the descriptor is structurally valid, false otherwise
     *               (an error frame is pushed onto JdbErrorHandler on failure)
     */
    public static function validate(JdbRelationMeta $meta)
    {
        $known = array(
            JdbRelationType::ONE_TO_MANY,
            JdbRelationType::MANY_TO_MANY,
            JdbRelationType::POLYMORPHIC,
            JdbRelationType::TEMPORAL,
        );
        if (!in_array($meta->type, $known, true)) {
            JdbErrorHandler::push('JdbRelationValidator', 'validate', 'Unknown type: ' . $meta->type);
            return false;
        }
        switch ($meta->type) {
            case JdbRelationType::ONE_TO_MANY:  return self::validateOneToMany($meta);
            case JdbRelationType::MANY_TO_MANY: return self::validateManyToMany($meta);
            case JdbRelationType::POLYMORPHIC:  return self::validatePolymorphic($meta);
            case JdbRelationType::TEMPORAL:     return self::validateTemporal($meta);
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Private per-type validators
    // -------------------------------------------------------------------------

    /**
     * Validates a ONE_TO_MANY relation descriptor.
     *
     * Requires: parentTable, childTable, fkField.
     *
     * @param  JdbRelationMeta $meta  Relation descriptor to validate
     * @return bool  True if all required fields are present and valid
     */
    private static function validateOneToMany(JdbRelationMeta $meta)
    {
        $ctx = 'ONE_TO_MANY';
        return self::requireTableName($meta->parentTable, $ctx, 'parentTable')
            && self::requireTableName($meta->childTable,  $ctx, 'childTable')
            && self::requireFieldName($meta->fkField,     $ctx, 'fkField');
    }

    /**
     * Validates a MANY_TO_MANY relation descriptor.
     *
     * Requires: parentTable, childTable, junctionTable, leftFk, rightFk.
     * payloadFields is optional and not validated here.
     *
     * @param  JdbRelationMeta $meta  Relation descriptor to validate
     * @return bool  True if all required fields are present and valid
     */
    private static function validateManyToMany(JdbRelationMeta $meta)
    {
        $ctx = 'MANY_TO_MANY';
        return self::requireTableName($meta->parentTable,   $ctx, 'parentTable')
            && self::requireTableName($meta->childTable,    $ctx, 'childTable')
            && self::requireTableName($meta->junctionTable, $ctx, 'junctionTable')
            && self::requireFieldName($meta->leftFk,        $ctx, 'leftFk')
            && self::requireFieldName($meta->rightFk,       $ctx, 'rightFk');
    }

    /**
     * Validates a POLYMORPHIC relation descriptor.
     *
     * Requires: childTable, typeField, idField.
     * parentTable is intentionally absent (resolved dynamically at query time).
     *
     * @param  JdbRelationMeta $meta  Relation descriptor to validate
     * @return bool  True if all required fields are present and valid
     */
    private static function validatePolymorphic(JdbRelationMeta $meta)
    {
        $ctx = 'POLYMORPHIC';
        return self::requireTableName($meta->childTable, $ctx, 'childTable')
            && self::requireFieldName($meta->typeField,  $ctx, 'typeField')
            && self::requireFieldName($meta->idField,    $ctx, 'idField');
    }

    /**
     * Validates a TEMPORAL relation descriptor.
     *
     * Requires: parentTable, childTable, fkField, timestampField.
     * archiveCutoff defaults to 0 (no cutoff) and is not validated here.
     *
     * @param  JdbRelationMeta $meta  Relation descriptor to validate
     * @return bool  True if all required fields are present and valid
     */
    private static function validateTemporal(JdbRelationMeta $meta)
    {
        $ctx = 'TEMPORAL';
        return self::requireTableName($meta->parentTable,    $ctx, 'parentTable')
            && self::requireTableName($meta->childTable,     $ctx, 'childTable')
            && self::requireFieldName($meta->fkField,        $ctx, 'fkField')
            && self::requireFieldName($meta->timestampField, $ctx, 'timestampField');
    }

    // -------------------------------------------------------------------------
    // Low-level identifier checkers
    // -------------------------------------------------------------------------

    /**
     * Asserts that $value is a non-empty, syntactically valid table name.
     *
     * Pushes an error frame onto JdbErrorHandler and returns false if the value
     * is null, empty, or fails JdbUtil::isValidIdentifier().
     *
     * @param  string|null $value    The table name to check
     * @param  string      $context  Relation type label used in error messages (e.g. 'ONE_TO_MANY')
     * @param  string      $field    Property name used in error messages (e.g. 'parentTable')
     * @return bool  True if the value is a valid table name, false otherwise
     */
    private static function requireTableName($value, $context, $field)
    {
        if ($value === null || $value === '') {
            JdbErrorHandler::push('JdbRelationValidator', 'requireTableName',
                "{$context}: {$field} is required");
            return false;
        }
        if (!JdbUtil::isValidIdentifier($value)) {
            JdbErrorHandler::push('JdbRelationValidator', 'requireTableName',
                "{$context}: invalid table name in {$field} [{$value}]");
            return false;
        }
        return true;
    }

    /**
     * Asserts that $value is a non-empty, syntactically valid field name.
     *
     * Pushes an error frame onto JdbErrorHandler and returns false if the value
     * is null, empty, or fails JdbUtil::isValidIdentifier().
     *
     * @param  string|null $value    The field name to check
     * @param  string      $context  Relation type label used in error messages (e.g. 'MANY_TO_MANY')
     * @param  string      $field    Property name used in error messages (e.g. 'leftFk')
     * @return bool  True if the value is a valid field name, false otherwise
     */
    private static function requireFieldName($value, $context, $field)
    {
        if ($value === null || $value === '') {
            JdbErrorHandler::push('JdbRelationValidator', 'requireFieldName',
                "{$context}: {$field} is required");
            return false;
        }
        if (!JdbUtil::isValidIdentifier($value)) {
            JdbErrorHandler::push('JdbRelationValidator', 'requireFieldName',
                "{$context}: invalid field name in {$field} [{$value}]");
            return false;
        }
        return true;
    }
}

