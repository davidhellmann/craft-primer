<?php

namespace davidhellmann\primer\decisions;

use craft\enums\PropagationMethod;

/**
 * The sites a preset spans: the sites a new entry of its section, created in the preset's site, would be saved to
 * (spec 4.2). A section that doesn't propagate everywhere has one preset per scope for an entry type.
 */
final class Scope
{
    /**
     * @param PropagationMethod $method The section's propagation method.
     * @param int $siteId The preset's site.
     * @param array<int, array{groupId: int, language: string}> $sectionSites The sites the section is enabled for, by ID.
     * @return int[] The site IDs, in the order of `$sectionSites`; none when the section isn't enabled for `$siteId`.
     */
    public static function siteIds(PropagationMethod $method, int $siteId, array $sectionSites): array
    {
        $own = $sectionSites[$siteId] ?? null;
        if ($own === null) {
            return [];
        }

        $siteIds = [];
        foreach ($sectionSites as $id => $site) {
            $included = match ($method) {
                PropagationMethod::All => true,
                PropagationMethod::SiteGroup => $site['groupId'] === $own['groupId'],
                PropagationMethod::Language => $site['language'] === $own['language'],
                // Custom: a new entry goes only to the sites it's given a status for; a preset stays in its own.
                PropagationMethod::None, PropagationMethod::Custom => $id === $siteId,
            };

            if ($included) {
                $siteIds[] = $id;
            }
        }

        return $siteIds;
    }
}
