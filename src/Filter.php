<?php declare(strict_types=1);

namespace BackofficeMcp;

/**
 * How a column may be filtered on.
 */
enum Filter: string
{
    /** Matches the value exactly. */
    case Exact = 'exact';

    /** Matches the value anywhere in the column (`LIKE %value%`). */
    case Partial = 'partial';

    /**
     * Accepts a leading `>`, `<`, `>=`, `<=` or `<>` before the value, which is what makes date
     * ranges possible. Without one, it matches exactly.
     */
    case Operator = 'operator';
}
