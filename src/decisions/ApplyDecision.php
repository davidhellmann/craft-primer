<?php

namespace davidhellmann\primer\decisions;

/**
 * Whether saving an entry applies its preset: the trigger, then the guards (spec section 5, steps 1 and 2).
 *
 * Plain flags in, so it needs neither Craft nor a database. The Applier reads them off the entry.
 */
final class ApplyDecision
{
    /**
     * @param bool $typeChanged The entry exists and its entry type differs from the stored one.
     */
    public static function shouldApply(
        bool $isNew,
        bool $typeChanged,
        bool $hasSection,
        bool $hasType,
        bool $propagating,
        bool $resaving,
        bool $duplicate,
        bool $updatingFromDerivative,
        bool $mergingCanonicalChanges,
        bool $revision,
    ): bool {
        // Trigger: the entry is being created, or switched to another entry type.
        if (!$isNew && !$typeChanged) {
            return false;
        }

        // Guards: entries outside a section, and saves that only copy or re-save what exists.
        return $hasSection
            && $hasType
            && !$propagating
            && !$resaving
            && !$duplicate
            && !$updatingFromDerivative
            && !$mergingCanonicalChanges
            && !$revision;
    }
}
