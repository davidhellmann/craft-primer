<?php

namespace davidhellmann\primer\services;

use Craft;
use craft\db\Table;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Db;
use craft\models\EntryType;
use davidhellmann\primer\decisions\FieldChoice;
use davidhellmann\primer\elements\Preset;
use Throwable;
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
     * Saves a preset, which writes its field choice to every scope (`Preset::afterSave()`), and empties the fields the
     * save takes out of the choice in every other site of every scope. A field that is chosen again then starts empty.
     */
    public function save(Preset $preset): bool
    {
        $before = $preset->sectionId && $preset->typeId ? $this->fieldUids($preset->sectionId, $preset->typeId) : [];

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            if (!Craft::$app->getElements()->saveElement($preset, updateSearchIndex: false)) {
                $transaction->rollBack();
                return false;
            }

            if (FieldChoice::removed($before, $preset->getFieldUids(), $preset->getLayoutFieldUids())) {
                $this->emptyUnchosenFieldsElsewhere($preset);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Saves the entry type's presets in every site but the saved preset's: each empties the fields it doesn't choose,
     * in its own site only. Fields of one site don't propagate, so each site is saved.
     */
    private function emptyUnchosenFieldsElsewhere(Preset $saved): void
    {
        $elements = Craft::$app->getElements();
        $presets = Preset::find()
            ->sectionId($saved->sectionId)
            ->typeId($saved->typeId)
            ->site('*')
            ->status(null)
            ->all();

        foreach ($presets as $preset) {
            if ($preset->id !== $saved->id || $preset->siteId !== $saved->siteId) {
                $elements->saveElement($preset, false, false, false);
            }
        }
    }

    /**
     * Forgets the presets' relations in fields that left the entry type's layout. Craft reads a relation field without
     * a stored value from its relations, so a field added back (with a new layout element UID) would otherwise show
     * the old elements again.
     */
    public function forgetFieldsNotInLayout(EntryType $type): void
    {
        $presetIds = Preset::find()->typeId($type->id)->site('*')->unique()->status(null)->ids();
        if (!$presetIds) {
            return;
        }

        $fieldIds = array_map(
            fn(CustomField $element) => $element->getField()->id,
            $type->getFieldLayout()->getCustomFieldElements(),
        );

        Db::delete(Table::RELATIONS, array_filter([
            'and',
            ['sourceId' => $presetIds],
            $fieldIds ? ['not', ['fieldId' => $fieldIds]] : null,
        ]));
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
