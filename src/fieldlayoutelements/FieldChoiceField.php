<?php

namespace davidhellmann\primer\fieldlayoutelements;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\FieldLayout\FieldLayoutElementContext;
use CraftCms\Cms\FieldLayout\LayoutElements\BaseNativeField;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use davidhellmann\primer\elements\Preset;
use Override;

use function CraftCms\Cms\t;

/**
 * A preset's field choice: which of the entry type's holdable fields it holds. The chosen fields follow it in the
 * preset's layout; changing the choice redraws them. The choice is the same in every scope.
 */
class FieldChoiceField extends BaseNativeField
{
    private const UID = 'e2d7a9c3-5f1b-4c6e-9d8a-0b4f3e7c1a65';

    #[Override]
    public string $attribute = 'fieldUids';

    public function __construct($config = [])
    {
        parent::__construct(['uid' => self::UID] + $config);
    }

    #[Override]
    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return t('Fields');
    }

    #[Override]
    protected function instructionsText(?ElementInterface $element = null, bool $static = false): ?string
    {
        return t('The fields this preset fills in a new entry, if they’re empty. They’re the same in every site.', category: 'primer');
    }

    #[Override]
    protected function uiControl(FieldLayoutElementContext $context): ?Control
    {
        $preset = $context->element;
        if (!$preset instanceof Preset) {
            return null;
        }

        return Choice::make($this->attribute())
            ->multiple()
            ->presentation(ChoicePresentation::AddMenu)
            ->options(array_map(fn(CustomField $element) => [
                'label' => (string)$element->label(),
                'value' => (string)$element->uid,
                'icon' => $element->getField()::icon(),
            ], $preset->getLayoutFieldElements()))
            ->value($preset->getFieldUids());
    }

    #[Override]
    protected function uiIcon(): ?string
    {
        return 'list-check';
    }
}
