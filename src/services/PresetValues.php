<?php

namespace davidhellmann\primer\services;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Field\Elements\ContentBlock;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Json;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * Forgets what presets hold in some of their fields, in every site: a field taken out of the field choice, or one
 * that left the entry type's layout or a Content Block's. A field chosen or added again starts empty.
 *
 * Craft finds a relation field's value in the element's content, and without it in its relations, and a Content Block
 * by its owner and field, so all three go.
 */
#[Singleton]
class PresetValues
{
    /**
     * @param int[] $ownerIds The presets, or the presets' Content Blocks, that hold the fields.
     * @param int[] $fieldIds The fields.
     * @param string[] $layoutElementUids The fields' layout elements, whose content goes.
     */
    public function forget(array $ownerIds, array $fieldIds, array $layoutElementUids = []): void
    {
        if (!$ownerIds || !$fieldIds) {
            return;
        }

        DB::table(Table::RELATIONS)->whereIn('sourceId', $ownerIds)->whereIn('fieldId', $fieldIds)->delete();

        if ($layoutElementUids) {
            $this->forgetContent($ownerIds, $layoutElementUids);
        }

        // A block goes with its relations: they belong to the block, not the preset.
        $blocks = ContentBlock::find()
            ->primaryOwnerId($ownerIds)
            ->fieldId($fieldIds)
            ->site('*')
            ->unique()
            ->status(null)
            ->all();

        foreach ($blocks as $block) {
            Elements::deleteElement($block, true);
        }
    }

    /**
     * @param int[] $ownerIds
     * @param string[] $layoutElementUids
     */
    private function forgetContent(array $ownerIds, array $layoutElementUids): void
    {
        $rows = DB::table(Table::ELEMENTS_SITES)->whereIn('elementId', $ownerIds)->whereNotNull('content')->get(['id', 'content']);

        foreach ($rows as $row) {
            $content = is_string($row->content) ? Json::decodeIfJson($row->content) : $row->content;
            if (!is_array($content)) {
                continue;
            }

            $kept = array_diff_key($content, array_flip($layoutElementUids));
            if ($kept !== $content) {
                DB::table(Table::ELEMENTS_SITES)
                    ->where('id', $row->id)
                    ->update(['content' => $kept ? Json::encode($kept) : null]);
            }
        }
    }
}
