<?php

namespace davidhellmann\primer\services;

use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\models\Preset as PresetModel;
use Illuminate\Container\Attributes\Singleton;

/**
 * Finds the presets of an entry type across scopes: a section that doesn't propagate everywhere has one preset
 * element per scope for each entry type. What they share (the field choice, their fate) the presets keep themselves,
 * on every save and delete (`Preset`).
 */
#[Singleton]
class Presets
{
    /**
     * The preset that applies to a new entry of a section's entry type in a site: the oldest, should two share the
     * site (`PresetQuery`). None when the entry type has left the section: its presets stay, hidden and inert.
     */
    public function inSite(int $sectionId, int $typeId, int $siteId): ?Preset
    {
        $preset = Preset::find()->sectionId($sectionId)->typeId($typeId)->siteId($siteId)->one();

        return $preset instanceof Preset && $preset->typeIsInSection() ? $preset : null;
    }

    /**
     * The field choice the presets of a section's entry type share; none before the first.
     *
     * @return string[]
     */
    public function sharedChoice(int $sectionId, int $typeId): array
    {
        $preset = Preset::find()->sectionId($sectionId)->typeId($typeId)->site('*')->one();

        return $preset instanceof Preset ? $preset->getFieldUids() : [];
    }

    /**
     * The IDs of a section's entry type's presets in every scope, drafts included.
     *
     * @return int[]
     */
    public function ids(int $sectionId, int $typeId): array
    {
        return PresetModel::query()
            ->where('sectionId', $sectionId)
            ->where('typeId', $typeId)
            ->pluck('id')
            ->map(fn($id) => (int)$id)
            ->all();
    }
}
