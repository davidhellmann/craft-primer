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
use davidhellmann\primer\decisions\LayoutChange;
use davidhellmann\primer\elements\Preset;
use yii\base\Component;

/**
 * Forgets the presets' values of fields that leave a field layout: an entry type's, or a Content Block's own layout
 * inside a preset. Craft's field layout events call it around every layout save.
 */
class LayoutCleanup extends Component
{
    /**
     * @var array<string, array<string, string>> Field UIDs by layout element UID of the layouts being saved, by
     *     layout UID, as they were before.
     */
    private array $_layoutsBefore = [];

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
     * Forgets the presets' values of every layout element that left the layout. Keyed by layout element UID, so a
     * field removed and added back, also in one save, or one of two instances of a field removed, can't bring the old
     * value back (`LayoutChange`).
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
}
