<?php

namespace davidhellmann\primer\fieldlayoutelements;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\FieldLayout\FieldLayoutElementContext;
use CraftCms\Cms\FieldLayout\LayoutElements\BaseNativeField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Controls\Choice;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\services\Presets;
use Override;

use function CraftCms\Cms\t;

/**
 * A new preset's section: one enabled for the preset's site, with an entry type a preset can hold fields of. Only in a new preset's layout; once
 * saved, a preset keeps its section.
 */
class SectionField extends BaseNativeField
{
    private const UID = 'b5a3e2d0-1c4f-4e8a-9b6d-3f7c0a2e5d11';

    #[Override]
    public string $attribute = 'sectionId';

    #[Override]
    public bool $required = true;

    public function __construct($config = [])
    {
        parent::__construct(['uid' => self::UID] + $config);
    }

    #[Override]
    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return t('Section');
    }

    #[Override]
    protected function uiControl(FieldLayoutElementContext $context): ?Control
    {
        $preset = $context->element;
        if (!$preset instanceof Preset) {
            return null;
        }

        $sections = app(Presets::class)->sections((int)$preset->siteId)
            ->map(fn(Section $section) => [
                'label' => $section->getUiLabel(),
                'value' => $section->id,
            ])
            ->all();

        return Choice::make($this->attribute())
            ->options($sections)
            ->withoutPlaceholder()
            ->value($preset->sectionId);
    }

    #[Override]
    protected function uiIcon(): ?string
    {
        return 'newspaper';
    }
}
