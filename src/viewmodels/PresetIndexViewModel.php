<?php

namespace davidhellmann\primer\viewmodels;

use CraftCms\Cms\Http\ViewModels\ElementTypeIndexViewModel;
use CraftCms\Cms\Support\Arr;
use Override;

/**
 * Craft's element index of presets, without inline editing: a preset's columns describe it, and its fields are
 * edited in the editor, where the field choice decides which there are.
 */
class PresetIndexViewModel extends ElementTypeIndexViewModel
{
    /** @return list<array<string, mixed>> */
    #[Override]
    public function data(): array
    {
        return array_map(
            fn(array $row) => Arr::except($row, ['inlineEditable', 'inlineInputHtml']),
            parent::data(),
        );
    }
}
