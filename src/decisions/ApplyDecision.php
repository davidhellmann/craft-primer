<?php

namespace davidhellmann\primer\decisions;

/**
 * Whether saving an entry applies a preset, and whose: the trigger, then the guards (spec section 5, steps 1 and 2).
 *
 * Plain values in, so it needs neither Craft nor a database. The Applier reads them off the entry.
 */
final class ApplyDecision
{
    /**
     * @param bool $typeSet The entry exists and its entry type was set in this request (Craft's editor sets it on
     *     every save, so this alone isn't a switch).
     * @param int|null $typeId The entry's entry type; null when it has none.
     * @param int|null $storedTypeId The entry type stored for the entry; only read when `$typeSet`.
     * @return int|null The entry type whose preset applies; null when none does.
     */
    public static function typeToApply(
        bool $isNew,
        bool $typeSet,
        ?int $typeId,
        ?int $storedTypeId,
        bool $hasSection,
        bool $propagating,
        bool $resaving,
        bool $duplicate,
        bool $updatingFromDerivative,
        bool $mergingCanonicalChanges,
        bool $revision,
    ): ?int {
        if ($typeId === null) {
            return null;
        }

        // Trigger: the entry is being created, or switched to another entry type.
        if (!$isNew && !($typeSet && $storedTypeId !== $typeId)) {
            return null;
        }

        // Guards: entries outside a section, and saves that only copy or re-save what exists.
        $applies = $hasSection
            && !$propagating
            && !$resaving
            && !$duplicate
            && !$updatingFromDerivative
            && !$mergingCanonicalChanges
            && !$revision;

        return $applies ? $typeId : null;
    }
}
