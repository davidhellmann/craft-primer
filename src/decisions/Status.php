<?php

namespace davidhellmann\primer\decisions;

/**
 * A preset's status (spec 4.6): `enabled` plus enabled per site, as an entry's. A preset applies where both are on.
 */
final class Status
{
    /**
     * The preset's `enabled` once its sites' statuses are set: on as soon as one site is, as Craft's
     * `ElementsController` sets it; switching sites off leaves it as it was.
     *
     * @param bool $enabled The preset's `enabled` before.
     * @param array<int, bool> $enabledForSite The sites' statuses being set, by site ID.
     */
    public static function enabled(bool $enabled, array $enabledForSite): bool
    {
        return $enabled || in_array(true, $enabledForSite, true);
    }
}
