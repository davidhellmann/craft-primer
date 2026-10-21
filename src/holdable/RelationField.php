<?php

namespace davidhellmann\primer\holdable;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets;
use craft\fields\BaseRelationField;
use craft\fields\Entries;
use craft\fields\Users;
use davidhellmann\primer\decisions\Truncation;

/**
 * A relation field: an Entries, Assets or Users field. Its value is the preset's elements, in preset order.
 */
final class RelationField extends HoldableField
{
    private const TYPES = [Entries::class, Assets::class, Users::class];

    public static function isRelationField(FieldInterface $field): bool
    {
        foreach (self::TYPES as $class) {
            if ($field instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public static function tryFrom(CustomField $element): ?self
    {
        return self::isRelationField($element->getField()) ? new self($element) : null;
    }

    public function emptyValue(): array
    {
        return [];
    }

    /**
     * Also checks the field in a preset's Content Block, on the block.
     */
    public function validateIn(ElementInterface $owner): void
    {
        $field = $this->element->getField();
        if ($field instanceof BaseRelationField) {
            $field->validateRelationCount($owner);
        }
    }

    public function fill(ElementInterface $target, mixed $presetValue): bool
    {
        if (!$target->isFieldEmpty($this->handle())) {
            return false;
        }

        $ids = $this->ids($presetValue);
        if ($ids) {
            $target->setFieldValue($this->handle(), $ids);
        }

        return (bool)$ids;
    }

    /**
     * The element IDs of a preset's value, in preset order, as many as the field allows.
     *
     * @return int[]
     */
    public function ids(mixed $presetValue): array
    {
        if (!$presetValue instanceof ElementQueryInterface) {
            return [];
        }

        $field = $this->element->getField();
        $maximum = $field instanceof BaseRelationField ? $field->maxRelations : null;

        return Truncation::toMaximum($presetValue->status(null)->ids(), $maximum);
    }
}
