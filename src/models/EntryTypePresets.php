<?php

namespace davidhellmann\primer\models;

use craft\models\EntryType;
use craft\models\Section;
use davidhellmann\primer\elements\Preset;

/**
 * The presets of entry types as the index and the section page show them: each entry type's preset in the screen's
 * site, and the sites its presets are in.
 */
final class EntryTypePresets
{
    /**
     * @var array<string, Preset>
     */
    private array $inSite = [];

    /**
     * @var array<string, int[]>
     */
    private array $siteIds = [];

    /**
     * @param Preset[] $inSite The presets in the screen's site, oldest first.
     * @param Preset[] $inEverySite The presets in every site, one per site.
     */
    public function __construct(array $inSite, array $inEverySite)
    {
        foreach ($inSite as $preset) {
            // Should two share the site, the oldest, the one that applies.
            $this->inSite[self::key((int)$preset->sectionId, (int)$preset->typeId)] ??= $preset;
        }

        foreach ($inEverySite as $preset) {
            $this->siteIds[self::key((int)$preset->sectionId, (int)$preset->typeId)][] = (int)$preset->siteId;
        }
    }

    /**
     * The entry type's preset in the screen's site.
     */
    public function inSite(Section $section, EntryType $type): ?Preset
    {
        return $this->inSite[self::key($section->id, $type->id)] ?? null;
    }

    /**
     * The sites the entry type's presets are in that the section is still enabled for.
     *
     * @return int[]
     */
    public function siteIds(Section $section, EntryType $type): array
    {
        return array_values(array_intersect(
            $this->siteIds[self::key($section->id, $type->id)] ?? [],
            array_keys($section->getSiteSettings()),
        ));
    }

    private static function key(int $sectionId, int $typeId): string
    {
        return "$sectionId:$typeId";
    }
}
