<?php

namespace davidhellmann\primer\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\ContentBlock;
use craft\fieldlayoutelements\CustomField;
use craft\fields\ContentBlock as ContentBlockField;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\FieldLayout;
use davidhellmann\primer\decisions\FieldChoice;
use davidhellmann\primer\decisions\LayoutChange;
use davidhellmann\primer\elements\db\PresetQuery;
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
     * @var array<string, array<string, string>> Field UIDs by layout element UID of the layouts being saved, by
     *     layout UID, as they were before.
     */
    private array $_layoutsBefore = [];

    /**
     * The presets of a section's entry type, one per scope, each in one of the sites of its scope.
     *
     * @return Preset[]
     */
    public function inAllScopes(int $sectionId, int $typeId): array
    {
        return $this->inEverySite($sectionId, $typeId)->unique()->all();
    }

    /**
     * The presets of a section's entry type in every site of every scope.
     */
    private function inEverySite(int $sectionId, int $typeId): PresetQuery
    {
        return Preset::find()->sectionId($sectionId)->typeId($typeId)->site('*');
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

        foreach ($this->inEverySite((int)$saved->sectionId, (int)$saved->typeId)->all() as $preset) {
            if ($preset->id !== $saved->id || $preset->siteId !== $saved->siteId) {
                $elements->saveElement($preset, false, false, false);
            }
        }
    }

    /**
     * Notes a field layout's elements before Craft saves it over, for `forgetFieldsThatLeft()` afterwards.
     */
    public function rememberLayout(FieldLayout $layout): void
    {
        if (!$layout->id || !$layout->uid) {
            return;
        }

        $config = (new Query())->select('config')->from(Table::FIELDLAYOUTS)->where(['id' => $layout->id])->scalar();
        $this->_layoutsBefore[$layout->uid] = $this->fieldUidsByElement(is_string($config) ? Json::decode($config) : null);
    }

    /**
     * Forgets the presets' values of every layout element that left a field layout: an entry type's, or a Content
     * Block's own layout inside a preset. Keyed by layout element UID, so a field removed and added back, also in
     * one save, or one of two instances of a field removed, can't bring the old value back (`LayoutChange`).
     */
    public function forgetFieldsThatLeft(FieldLayout $layout): void
    {
        $before = $layout->uid ? ArrayHelper::remove($this->_layoutsBefore, $layout->uid) : null;
        if ($before === null) {
            return;
        }

        $left = LayoutChange::leftFields($before, $this->fieldUidsByElement($layout->getConfig()));
        $fieldIds = $left ? array_values(Db::idsByUids(Table::FIELDS, $left)) : [];
        $ownerIds = $fieldIds ? $this->ownersOf($layout) : [];
        if (!$ownerIds) {
            return;
        }

        Db::delete(Table::RELATIONS, ['sourceId' => $ownerIds, 'fieldId' => $fieldIds]);

        // A block goes with its relations: they belong to the block, not the preset.
        $blocks = ContentBlock::find()->primaryOwnerId($ownerIds)->fieldId($fieldIds)->site('*')->unique()->status(null)->all();
        foreach ($blocks as $block) {
            Craft::$app->getElements()->deleteElement($block, true);
        }
    }

    /**
     * The elements that hold presets' values in a layout's fields: the presets of the entry types with that layout,
     * or the presets' blocks of the Content Block field with that layout.
     *
     * @return int[]
     */
    private function ownersOf(FieldLayout $layout): array
    {
        if ($layout->provider instanceof ContentBlockField) {
            $presetIds = Preset::find()->site('*')->unique()->ids();

            return $presetIds
                ? ContentBlock::find()->fieldId($layout->provider->id)->primaryOwnerId($presetIds)->site('*')->unique()->status(null)->ids()
                : [];
        }

        $typeIds = (new Query())->select('id')->from(Table::ENTRYTYPES)->where(['fieldLayoutId' => $layout->id])->column();

        return $typeIds ? Preset::find()->typeId($typeIds)->site('*')->unique()->ids() : [];
    }

    /**
     * The custom fields of a field layout's config: field UIDs by layout element UID.
     *
     * @param array<string, mixed>|null $config
     * @return array<string, string>
     */
    private function fieldUidsByElement(?array $config): array
    {
        $fieldUids = [];
        foreach ($config['tabs'] ?? [] as $tab) {
            foreach ($tab['elements'] ?? [] as $element) {
                if (($element['type'] ?? null) === CustomField::class && isset($element['uid'], $element['fieldUid'])) {
                    $fieldUids[$element['uid']] = $element['fieldUid'];
                }
            }
        }

        return $fieldUids;
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
