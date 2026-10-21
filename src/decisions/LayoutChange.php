<?php

namespace davidhellmann\primer\decisions;

/**
 * What presets forget when a field layout is saved (spec 4.4): the values of every layout element that left it.
 *
 * Craft finds a relation field's value without a stored one in its relations, and a Content Block by its owner and
 * field, both by field rather than by layout element. A field whose layout element left, even if the same field
 * stays or comes back under a new one, would otherwise show its old value again.
 */
final class LayoutChange
{
    /**
     * @param array<string, string> $before Field UIDs by layout element UID, before the save.
     * @param array<string, string> $after Field UIDs by layout element UID, after it.
     * @return string[] The UIDs of the fields whose layout elements left, each once, in `$before` order.
     */
    public static function leftFields(array $before, array $after): array
    {
        $left = [];
        foreach ($before as $elementUid => $fieldUid) {
            if (($after[$elementUid] ?? null) !== $fieldUid) {
                $left[] = $fieldUid;
            }
        }

        return array_values(array_unique($left));
    }
}
