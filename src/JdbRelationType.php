<?php

/**
 * JdbRelationType – String constants for the four supported relation kinds.
 *
 * Using string constants (rather than integers) makes serialised relation
 * metadata self-describing and eases debugging.
 */
class JdbRelationType
{
    /**
     * Parent → child via a foreign-key field stored in the child table.
     * Classic 1-N: one parent, many children.
     */
    const ONE_TO_MANY  = 'one_to_many';

    /**
     * Two entity tables linked through a dedicated junction/pivot table.
     * The junction may carry an optional payload (enriched many-to-many).
     */
    const MANY_TO_MANY = 'many_to_many';

    /**
     * A single child table that relates to multiple parent types via a
     * composite (type_field, id_field) key stored in the child row.
     * The concrete parent table is resolved dynamically at query time.
     */
    const POLYMORPHIC  = 'polymorphic';

    /**
     * Like ONE_TO_MANY, but children are additionally grouped and filtered
     * by a numeric timestamp field.  Rows whose timestamp falls before a
     * configured cutoff are treated as read-only archived data; any attempt
     * to mutate them is rejected with an explicit error.
     */
    const TEMPORAL     = 'temporal';
}
