<?php

namespace davidhellmann\primer\holdable;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\ContentBlock as ContentBlockFieldType;
use CraftCms\Cms\Field\Elements\ContentBlock as ContentBlockElement;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use davidhellmann\primer\elements\Preset;

/**
 * A Content Block whose own layout has relation fields (first level only), held as a whole: inside a preset the block
 * holds only those relation fields, and what else an entry's block holds stays.
 */
final class ContentBlock extends HoldableField
{
    /**
     * @param RelationField[] $inner The relation fields of the block's own layout.
     */
    private function __construct(
        CustomField $element,
        private readonly array $inner,
    ) {
        parent::__construct($element);
    }

    public static function tryFrom(CustomField $element): ?self
    {
        $field = $element->getField();
        if (!$field instanceof ContentBlockFieldType) {
            return null;
        }

        $inner = array_values(array_filter(array_map(
            fn(CustomField $innerElement) => RelationField::tryFrom($innerElement),
            $field->getFieldLayout()->getCustomFieldElements(),
        )));

        return $inner ? new self($element, $inner) : null;
    }

    /**
     * Checks the block's relation fields, and shows their errors at the block. A preset that was never saved can't
     * fill a block yet (Craft shows its message instead), so an empty field isn't held to a minimum.
     */
    public function validateIn(Preset $preset): void
    {
        $block = $preset->getFieldValue($this->handle());
        if (!$block instanceof ContentBlockElement) {
            return;
        }

        foreach ($this->inner as $inner) {
            if (($preset->id && !$preset->getIsUnpublishedDraft()) || !$block->isFieldEmpty($inner->handle())) {
                $inner->validateOn($block);
            }
        }

        if ($block->errors()->isNotEmpty()) {
            $preset->addModelErrors($block, $this->handle());
        }
    }

    /**
     * Fills the empty relation fields of the target's block with the preset block's elements.
     *
     * The block is set through its array form, which builds a fresh block: the block's current values go along,
     * or what an import or the editor already put in the block would be lost.
     */
    public function fill(ElementInterface $target, mixed $presetValue): bool
    {
        $block = $target->getFieldValue($this->handle());
        if (!$presetValue instanceof ContentBlockElement || !$block instanceof ContentBlockElement) {
            return false;
        }

        $values = [];
        foreach ($this->inner as $inner) {
            if ($block->isFieldEmpty($inner->handle())) {
                $ids = $inner->ids($presetValue->getFieldValue($inner->handle()));
                if ($ids) {
                    $values[$inner->handle()] = $ids;
                }
            }
        }

        if ($values) {
            $target->setFieldValue($this->handle(), ['fields' => array_merge($block->getSerializedFieldValues(), $values)]);
        }

        return (bool)$values;
    }
}
