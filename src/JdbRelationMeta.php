<?php
require_once __DIR__ . "/JdbRelationType.php";

/**
 * JdbRelationMeta – Immutable, self-validating relation descriptor.
 *
 * Always constructed via the four static factory methods; never with `new`.
 *
 * Common fields (all types):
 *   $type        — JdbRelationType constant
 *   $parentTable — source/parent table name (null for POLYMORPHIC)
 *   $childTable  — target/child table name
 *
 * ONE_TO_MANY specific:
 *   $fkField     — field in $childTable that holds the parent's ID
 *
 * MANY_TO_MANY specific:
 *   $junctionTable — pivot/junction table name
 *   $leftFk        — FK field in junction referencing the parent
 *   $rightFk       — FK field in junction referencing the child
 *   $payloadFields — additional fields stored in the junction row (optional)
 *
 * POLYMORPHIC specific:
 *   $typeField — field in $childTable holding the parent table/class name
 *   $idField   — field in $childTable holding the parent record ID
 *
 * TEMPORAL specific:
 *   $fkField        — same semantics as ONE_TO_MANY
 *   $timestampField — numeric (Unix timestamp) field used for time filtering
 *   $archiveCutoff  — Unix timestamp; rows with $timestampField < $archiveCutoff
 *                     are treated as immutable archives (writes rejected)
 */
class JdbRelationMeta
{
    /** @var string JdbRelationType constant */
    public $type;

    /** @var string|null Parent table name (null for POLYMORPHIC) */
    public $parentTable;

    /** @var string Child / target table name */
    public $childTable;

    /** @var string|null FK field in child (ONE_TO_MANY, TEMPORAL) */
    public $fkField;

    /** @var string|null Junction / pivot table name (MANY_TO_MANY) */
    public $junctionTable;

    /** @var string|null FK in junction → parent (MANY_TO_MANY) */
    public $leftFk;

    /** @var string|null FK in junction → child (MANY_TO_MANY) */
    public $rightFk;

    /** @var string[] Extra fields carried by each junction row (MANY_TO_MANY) */
    public $payloadFields;

    /** @var string|null Field holding the parent type name (POLYMORPHIC) */
    public $typeField;

    /** @var string|null Field holding the parent record ID (POLYMORPHIC) */
    public $idField;

    /** @var string|null Numeric timestamp field name (TEMPORAL) */
    public $timestampField;

    /**
     * @var int|null Unix timestamp before which child rows are read-only (TEMPORAL).
     *               0 means no cutoff (all rows are writable).
     */
    public $archiveCutoff;

    // -------------------------------------------------------------------------
    // Private constructor – always use a factory method
    // -------------------------------------------------------------------------

    private function __construct() {}

    // -------------------------------------------------------------------------
    // Factory methods
    // -------------------------------------------------------------------------

    /**
     * Creates a ONE_TO_MANY relation descriptor.
     *
     * @param  string $parentTable Parent table name
     * @param  string $childTable  Child table name
     * @param  string $fkField     FK field in $childTable pointing to the parent's ID
     * @return JdbRelationMeta
     */
    public static function oneToMany($parentTable, $childTable, $fkField)
    {
        $m               = new self();
        $m->type         = JdbRelationType::ONE_TO_MANY;
        $m->parentTable  = (string)$parentTable;
        $m->childTable   = (string)$childTable;
        $m->fkField      = (string)$fkField;
        $m->payloadFields = array();
        return $m;
    }

    /**
     * Creates a MANY_TO_MANY relation descriptor.
     *
     * @param  string   $parentTable    Parent / source table name
     * @param  string   $childTable     Child / target table name
     * @param  string   $junctionTable  Pivot / bridge table name
     * @param  string   $leftFk         FK in junction → parent
     * @param  string   $rightFk        FK in junction → child
     * @param  string[] $payloadFields  Optional fields stored in the junction row
     * @return JdbRelationMeta
     */
    public static function manyToMany($parentTable, $childTable, $junctionTable,
                                      $leftFk, $rightFk, array $payloadFields = array())
    {
        $m                = new self();
        $m->type          = JdbRelationType::MANY_TO_MANY;
        $m->parentTable   = (string)$parentTable;
        $m->childTable    = (string)$childTable;
        $m->junctionTable = (string)$junctionTable;
        $m->leftFk        = (string)$leftFk;
        $m->rightFk       = (string)$rightFk;
        $m->payloadFields = $payloadFields;
        return $m;
    }

    /**
     * Creates a POLYMORPHIC relation descriptor.
     *
     * The parent table is not fixed; it is resolved dynamically from the
     * $typeField value of each child row.
     *
     * @param  string $childTable  Table holding polymorphic references
     * @param  string $typeField   Field storing the parent table/class name
     * @param  string $idField     Field storing the parent record ID
     * @return JdbRelationMeta
     */
    public static function polymorphic($childTable, $typeField, $idField)
    {
        $m              = new self();
        $m->type        = JdbRelationType::POLYMORPHIC;
        $m->parentTable = null;
        $m->childTable  = (string)$childTable;
        $m->typeField   = (string)$typeField;
        $m->idField     = (string)$idField;
        $m->payloadFields = array();
        return $m;
    }

    /**
     * Creates a TEMPORAL / ARCHIVAL relation descriptor.
     *
     * @param  string $parentTable     Parent / source table name
     * @param  string $childTable      Child / target table name
     * @param  string $fkField         FK field in $childTable
     * @param  string $timestampField  Numeric Unix-timestamp field in $childTable
     * @param  int    $archiveCutoff   Rows with $timestampField < $archiveCutoff are
     *                                 treated as immutable; 0 = no cutoff
     * @return JdbRelationMeta
     */
    public static function temporal($parentTable, $childTable, $fkField,
                                    $timestampField, $archiveCutoff = 0)
    {
        $m                 = new self();
        $m->type           = JdbRelationType::TEMPORAL;
        $m->parentTable    = (string)$parentTable;
        $m->childTable     = (string)$childTable;
        $m->fkField        = (string)$fkField;
        $m->timestampField = (string)$timestampField;
        $m->archiveCutoff  = (int)$archiveCutoff;
        $m->payloadFields  = array();
        return $m;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the set of all table names involved in this relation.
     * Used by JdbAggLock to build the sorted acquisition list.
     *
     * @return string[]  Unique table names, unsorted
     */
    public function involvedTables()
    {
        $tables = array();
        if (!empty($this->parentTable))   $tables[] = $this->parentTable;
        if (!empty($this->childTable))    $tables[] = $this->childTable;
        if (!empty($this->junctionTable)) $tables[] = $this->junctionTable;
        return array_values(array_unique($tables));
    }
}
