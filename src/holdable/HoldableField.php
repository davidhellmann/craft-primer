<?php

namespace davidhellmann\primer\holdable;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use davidhellmann\primer\elements\Preset;

/**
 * A holdable field: a layout element whose field a preset can hold, a relation field or a Content Block with relation
 * fields. One adapter per kind; the preset and the Applier ask it what to do instead of telling the kinds
 * apart themselves.
 */
abstract class HoldableField
{
    protected function __construct(
        protected readonly CustomField $element,
    ) {
    }

    /**
     * The layout element as a holdable field, or null when a preset can't hold its field.
     */
    public static function of(CustomField $element): ?self
    {
        return RelationField::tryFrom($element) ?? ContentBlock::tryFrom($element);
    }

    /**
     * The field's handle in its layout.
     */
    public function handle(): string
    {
        return $this->element->attribute();
    }

    /**
     * Adds an error to the preset where the field holds fewer or more relations than it allows. Craft checks them
     * only when it saves an enabled element in the control panel (its live scenario), and on the preset's own fields.
     */
    abstract public function validateIn(Preset $preset): void;

    /**
     * Fills the field of an element where it is empty with the preset's value, as many elements as the field allows.
     *
     * @return bool Whether it filled anything.
     */
    abstract public function fill(ElementInterface $target, mixed $presetValue): bool;
}
