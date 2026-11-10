<?php

namespace davidhellmann\primer\fieldlayoutelements;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\FieldLayout\FieldLayoutElementContext;
use CraftCms\Cms\FieldLayout\LayoutElements\BaseNativeField;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Controls\Choice;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\services\Presets;
use Override;

use function CraftCms\Cms\t;

/**
 * A new preset's entry type: one of its section's with fields a preset can hold, with the entry type's own icon and
 * colour. Only in a new preset's
 * layout, once it has a section; once saved, a preset keeps its entry type.
 */
class EntryTypeField extends BaseNativeField
{
    private const UID = 'c8e1f4a7-2d5b-4f9c-8a3e-6b0d1c7f2e94';

    #[Override]
    public string $attribute = 'typeId';

    #[Override]
    public bool $required = true;

    public function __construct($config = [])
    {
        parent::__construct(['uid' => self::UID] + $config);
    }

    #[Override]
    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return t('Entry Type');
    }

    #[Override]
    protected function uiControl(FieldLayoutElementContext $context): ?Control
    {
        $preset = $context->element;
        $section = $preset instanceof Preset ? $preset->getSection() : null;
        if (!$section) {
            return null;
        }

        return Choice::make($this->attribute())
            ->options(array_map(fn(EntryType $type) => array_filter([
                'label' => $type->getUiLabel(),
                'value' => $type->id,
                'icon' => $type->icon,
                'color' => $type->color?->value,
            ], fn($value) => $value !== null), app(Presets::class)->entryTypes($section)))
            ->withoutPlaceholder()
            ->value($preset->typeId);
    }

    #[Override]
    protected function uiIcon(): ?string
    {
        return 'shapes';
    }
}
