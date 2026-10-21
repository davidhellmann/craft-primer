<?php

namespace davidhellmann\primer\decisions;

use craft\enums\PropagationMethod;

/**
 * The sites a preset spans: the sites a new entry of its section, created in the preset's site, would be saved to,
 * but none another preset of the same section and entry type holds. A section that doesn't propagate
 * everywhere has one preset per scope for an entry type, and every site belongs to one of them.
 */
final class Scope
{
    /**
     * @param PropagationMethod $method The section's propagation method.
     * @param int $siteId The preset's site.
     * @param array<int, array{groupId: int, language: string}> $sectionSites The sites the section is enabled for, by ID.
     * @param int[] $taken The sites another preset of the section and entry type holds, such as after a site was added
     *     to the section or its propagation method changed. The preset's own site stays, for validation to refuse.
     * @return int[] The site IDs, in the order of `$sectionSites`; none when the section isn't enabled for `$siteId`.
     */
    public static function siteIds(PropagationMethod $method, int $siteId, array $sectionSites, array $taken = []): array
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

            if ($included && ($id === $siteId || !in_array($id, $taken, true))) {
                $siteIds[] = $id;
            }
        }

        return $siteIds;
    }
}
