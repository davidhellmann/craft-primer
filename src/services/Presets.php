<?php

namespace davidhellmann\primer\services;

use Craft;
use davidhellmann\primer\elements\Preset;
use yii\base\Component;

/**
 * The presets of an entry type across scopes: a section that doesn't propagate everywhere has one preset
 * element per scope for each entry type, and they share their field choice and their fate.
 */
class Presets extends Component
{
    /**
     * The presets of a section's entry type, one per scope, each in one of the sites of its scope.
     *
     * @return Preset[]
     */
    public function inAllScopes(int $sectionId, int $typeId): array
    {
        return Preset::find()->sectionId($sectionId)->typeId($typeId)->site('*')->unique()->all();
    }

    /**
     * The field choice of the entry type's presets, which a new preset in another scope starts with.
     *
     * @return string[]
     */
    public function fieldUids(int $sectionId, int $typeId): array
    {
        return ($this->inAllScopes($sectionId, $typeId)[0] ?? null)?->getFieldUids() ?? [];
    }

    /**
     * Deletes the preset and the presets of its section and entry type in every other scope. Presets have no
     * trash to restore them from, so they go for good.
     */
    public function deleteInAllScopes(Preset $preset): void
    {
        $elements = Craft::$app->getElements();

        foreach ($this->inAllScopes((int)$preset->sectionId, (int)$preset->typeId) as $sibling) {
            $elements->deleteElement($sibling, true);
        }
    }
}
