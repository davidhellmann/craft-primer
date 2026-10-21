<?php

namespace davidhellmann\primer\holdable;

use craft\base\ElementInterface;
use craft\fieldlayoutelements\CustomField;
use davidhellmann\primer\elements\Preset;

/**
 * A holdable field: a layout element whose field a preset can hold, a relation field or a Content Block with relation
 * fields. One adapter per kind; the preset, the Applier and the form ask it what to do instead of telling the kinds
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
     * The value that empties the field in a preset.
     */
    abstract public function emptyValue(): mixed;

    /**
     * Adds an error to the preset where the field holds fewer or more relations than it allows.
     */
    abstract public function validateIn(Preset $preset): void;

    /**
     * Fills the field of an element where it is empty with the preset's value, as many elements as the field allows.
     *
     * @return bool Whether it filled anything.
     */
    abstract public function fill(ElementInterface $target, mixed $presetValue): bool;
}
