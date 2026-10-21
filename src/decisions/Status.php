<?php

namespace davidhellmann\primer\decisions;

/**
 * A preset's status (spec 4.6): `enabled` plus enabled per site, as an entry's. A preset applies where both are on.
 *
 * Craft keeps "off" in `enabled` when an element supports a single site, and per site otherwise. A preset switched
 * off in a one-site scope is therefore off as a whole, and stays so in every other site its scope gains later.
 */
final class Status
{
    /**
     * The status once sites are switched, as Craft's status field switches an entry's: on as soon as one site is;
     * switching sites off leaves `enabled` as it was. A preset that was off as a whole stays off in the sites of its
     * scope that aren't switched, so switching one site on doesn't switch the others on with it.
     *
     * @param bool $enabled The preset's `enabled` before.
     * @param array<int, bool> $switching The sites being switched, by site ID. Sites outside the scope are left out.
     * @param int[] $scopeSiteIds The sites of the preset's scope.
     * @return array{enabled: bool, sites: array<int, bool>} `enabled`, and the status of each site to set, by site ID.
     */
    public static function switched(bool $enabled, array $switching, array $scopeSiteIds): array
    {
        $switching = array_intersect_key($switching, array_flip($scopeSiteIds));

        $sites = $switching;
        if (!$enabled) {
            foreach ($scopeSiteIds as $siteId) {
                $sites[$siteId] ??= false;
            }
        }

        return [
            'enabled' => $enabled || in_array(true, $switching, true),
            'sites' => $sites,
        ];
    }

    /**
     * Whether the preset shows as on in each site, as the sidebar's status field: what is set on it (a save that
     * failed keeps what was posted), else what is stored, else on, as a new preset starts. Nowhere while `enabled` is
     * off.
     *
     * @param int[] $siteIds The sites to show.
     * @param array<int, bool|null> $set The statuses set on the preset, by site ID; null where none is.
     * @param array<int, bool> $stored Whether the stored preset is on, by site ID; none for a new preset.
     * @return array<int, bool> By site ID, in the order of `$siteIds`.
     */
    public static function shown(bool $enabled, array $siteIds, array $set, array $stored): array
    {
        $shown = [];
        foreach ($siteIds as $siteId) {
            $shown[$siteId] = $enabled && ($set[$siteId] ?? $stored[$siteId] ?? true);
        }

        return $shown;
    }
}
