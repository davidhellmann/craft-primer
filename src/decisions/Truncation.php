<?php

namespace davidhellmann\primer\decisions;

/**
 * Fits a preset's elements into a field with a maximum number of relations: the first ones, in preset order.
 */
final class Truncation
{
    /**
     * @param int[] $ids
     * @param int|null $maximum The field's `maxRelations`; null or 0 means no limit.
     * @return int[]
     */
    public static function toMaximum(array $ids, ?int $maximum): array
    {
        $ids = array_values($ids);

        return $maximum ? array_slice($ids, 0, $maximum) : $ids;
    }
}
