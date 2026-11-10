<?php

namespace davidhellmann\primer\holdable;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementCollection;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Field\Assets;
use CraftCms\Cms\Field\BaseRelationField;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Entries;
use CraftCms\Cms\Field\Users;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use davidhellmann\primer\elements\Preset;
use Illuminate\Support\Facades\Validator;
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

    public function validateIn(Preset $preset): void
    {
        $this->validateOn($preset);
    }

    /**
     * Checks the field's number of relations on its owner: a preset, or a preset's Content Block.
     */
    public function validateOn(ElementInterface $owner): void
    {
        $field = $this->element->getField();
        $value = $owner->getFieldValue($this->handle());
        if (!$field instanceof BaseRelationField || !($value instanceof ElementQueryInterface || $value instanceof ElementCollection)) {
            return;
        }

        $validator = Validator::make([], []);
        $field->validateRelationCount($owner, $this->handle(), $value, $validator);
        $owner->errors()->merge($validator->errors());
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
        $ids = match (true) {
            $presetValue instanceof ElementQueryInterface => $presetValue->status(null)->ids(),
            $presetValue instanceof ElementCollection => $presetValue->ids()->all(),
            default => [],
        };

        $field = $this->element->getField();
        $maximum = $field instanceof BaseRelationField ? $field->maxRelations : null;

        return Truncation::toMaximum(array_map('intval', $ids), $maximum);
    }
}
