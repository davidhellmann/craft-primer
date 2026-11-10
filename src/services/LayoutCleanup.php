<?php

namespace davidhellmann\primer\services;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Field\ContentBlock as ContentBlockField;
use CraftCms\Cms\Field\Elements\ContentBlock;
use CraftCms\Cms\Field\Events\FieldLayoutSaved;
use CraftCms\Cms\FieldLayout\FieldLayout;
use davidhellmann\primer\decisions\LayoutChange;
use davidhellmann\primer\elements\Preset;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * Forgets the presets' values of fields that leave a field layout: an entry type's, or a Content Block's own layout
 * inside a preset. Listens to every field layout save.
 */
#[Singleton]
class LayoutCleanup
{
    public function __construct(
        private readonly PresetValues $presetValues,
        private readonly Applier $applier,
    ) {
    }

    /**
     * Forgets the presets' values of every layout element that left the layout. Keyed by layout element UID, so a
     * field removed and added back, also in one save, or one of two instances of a field removed, can't bring the old
     * value back (`LayoutChange`).
     */
    public function handle(FieldLayoutSaved $event): void
    {
        $this->applier->reset();

        if ($event->isNew || $event->previousConfig === null) {
            return;
        }

        $left = LayoutChange::leftFields(
            $this->fieldUidsByElement($event->previousConfig),
            $this->fieldUidsByElement($event->layout->getConfig()),
        );
        $fieldIds = $left
            ? array_values(array_map('intval', DB::table(Table::FIELDS)->idsByUids($left)))
            : [];

        if ($fieldIds) {
            $this->presetValues->forget($this->ownersOf($event->layout), $fieldIds);
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
            $presetIds = Preset::find()->site('*')->unique()->drafts(null)->ids();

            return $presetIds
                ? ContentBlock::find()->fieldId($layout->provider->id)->primaryOwnerId($presetIds)->site('*')->unique()->status(null)->ids()
                : [];
        }

        $typeIds = DB::table(Table::ENTRYTYPES)->where('fieldLayoutId', $layout->id)->pluck('id')->all();

        return $typeIds ? Preset::find()->typeId($typeIds)->site('*')->unique()->drafts(null)->ids() : [];
    }

    /**
     * The custom fields of a field layout's config: field UIDs by layout element UID. A layout element with a field
     * UID is a custom field, whatever Craft version wrote its type.
     *
     * @param array<string, mixed>|null $config
     * @return array<string, string>
     */
    private function fieldUidsByElement(?array $config): array
    {
        $fieldUids = [];
        foreach ($config['tabs'] ?? [] as $tab) {
            foreach ($tab['elements'] ?? [] as $element) {
                if (isset($element['uid'], $element['fieldUid'])) {
                    $fieldUids[$element['uid']] = $element['fieldUid'];
                }
            }
        }

        return $fieldUids;
    }
}
