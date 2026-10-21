<?php

namespace davidhellmann\primer\services;

use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\models\EntryTypePresets;
use yii\base\Component;

/**
 * Finds the presets of an entry type across scopes: a section that doesn't propagate everywhere has one preset
 * element per scope for each entry type. What they share (the field choice, their fate) the presets keep themselves,
 * on every save and delete (`Preset`).
 */
class Presets extends Component
{
    /**
     * The preset that applies to a new entry of a section's entry type in a site: the oldest, should two share the
     * site (`PresetQuery`). None when the entry type has left the section: its presets stay, hidden and inert.
     */
    public function inSite(int $sectionId, int $typeId, int $siteId): ?Preset
    {
        $preset = Preset::find()->sectionId($sectionId)->typeId($typeId)->siteId($siteId)->one();

        return $preset?->typeIsInSection() ? $preset : null;
    }

    /**
     * The site's preset of a section's entry type, to edit: the saved one, or a new one, which starts with the field
     * choice of the entry type's presets in other scopes. None when the section isn't enabled for the site.
     */
    public function toEdit(Section $section, EntryType $type, Site $site): ?Preset
    {
        $saved = Preset::find()->sectionId($section->id)->typeId($type->id)->siteId($site->id)->one();
        if ($saved) {
            return $saved;
        }

        if (!isset($section->getSiteSettings()[$site->id])) {
            return null;
        }

        $preset = new Preset([
            'siteId' => $site->id,
            'sectionId' => $section->id,
            'typeId' => $type->id,
        ]);
        $preset->setFieldUids($this->sharedChoice($section->id, $type->id));

        return $preset;
    }

    /**
     * The field choice the presets of a section's entry type share; none before the first.
     *
     * @return string[]
     */
    public function sharedChoice(int $sectionId, int $typeId): array
    {
        return Preset::find()->sectionId($sectionId)->typeId($typeId)->site('*')->one()?->getFieldUids() ?? [];
    }

    /**
     * The site's presets of the entry types of one section or all, and the sites each entry type's presets are in.
     */
    public function ofEntryTypes(Site $site, ?Section $section = null): EntryTypePresets
    {
        return new EntryTypePresets(
            Preset::find()->sectionId($section?->id)->siteId($site->id)->all(),
            Preset::find()->sectionId($section?->id)->site('*')->all(),
        );
    }
}
