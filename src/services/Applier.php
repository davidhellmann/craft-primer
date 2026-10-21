<?php

namespace davidhellmann\primer\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\BaseRelationField;
use craft\models\FieldLayout;
use davidhellmann\primer\decisions\ApplyDecision;
use davidhellmann\primer\decisions\Truncation;
use davidhellmann\primer\elements\Preset;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Fills the empty chosen relation fields of an entry that is being created or switched to another entry type,
 * from the preset of its section, entry type and site (spec section 5). Craft then saves and propagates the entry as usual.
 */
class Applier extends Component
{
    /**
     * @var array<string, Preset|false> Presets by section, entry type and site, for this request.
     */
    private array $_presets = [];

    /**
     * Handles an entry's `beforeSave`. Never stops the save: a failure is logged and the entry saves without.
     */
    public function apply(Entry $entry, bool $isNew): void
    {
        // `Entry::setTypeId()` empties `fieldLayoutId`, and Craft writes it back only after `beforeSave`.
        // Saves that neither create the entry nor set its type leave after these two checks.
        $typeSet = $entry->id && $entry->fieldLayoutId === null;
        if (!$isNew && !$typeSet) {
            return;
        }

        $typeId = $this->typeId($entry);

        $apply = ApplyDecision::shouldApply(
            isNew: $isNew,
            // The editor sets the type on every save, so one query compares it with the stored one.
            typeChanged: !$isNew && $typeId !== null && $this->storedTypeId($entry) !== $typeId,
            hasSection: $entry->sectionId !== null,
            hasType: $typeId !== null,
            propagating: $entry->propagating,
            resaving: $entry->resaving,
            duplicate: $entry->duplicateOf !== null,
            updatingFromDerivative: $entry->updatingFromDerivative,
            mergingCanonicalChanges: $entry->mergingCanonicalChanges,
            revision: $entry->getIsRevision(),
        );

        if (!$apply || $typeId === null) {
            return;
        }

        try {
            $filled = $this->fill($entry, $typeId);
        } catch (Throwable $e) {
            Craft::warning(sprintf("Primer couldn't apply the preset to %s: %s", $this->describe($entry), $e), __METHOD__);
            return;
        }

        if ($filled) {
            $this->logOnceSaved($entry, $filled);
        }
    }

    /**
     * Forgets the presets loaded so far, after one was saved or deleted.
     */
    public function reset(): void
    {
        $this->_presets = [];
    }

    /**
     * Fills the entry's empty chosen fields from its preset.
     *
     * @return string[] The handles of the fields it filled.
     */
    private function fill(Entry $entry, int $typeId): array
    {
        $preset = $this->preset((int)$entry->sectionId, $typeId, $entry->siteId);
        $layout = $entry->getFieldLayout();

        // Presets have no statuses, so the query returned it whatever its state.
        if (!$preset || !$preset->enabled || !$preset->getEnabledForSite() || !$layout) {
            return [];
        }

        $filled = [];
        foreach ($preset->getChosenFieldElements() as $source) {
            $target = $this->relationFieldElement($layout, $source->uid);
            if (!$target) {
                continue;
            }

            $handle = $target->attribute();
            if (!$entry->isFieldEmpty($handle)) {
                continue;
            }

            $ids = $this->ids($preset->getFieldValue($source->attribute()), $target);
            if ($ids) {
                $entry->setFieldValue($handle, $ids);
                $filled[] = $handle;
            }
        }

        return $filled;
    }

    /**
     * One info line per filled entry, written once it is saved: a new entry has no ID before.
     *
     * @param string[] $handles
     */
    private function logOnceSaved(Entry $entry, array $handles): void
    {
        $category = __METHOD__;
        $log = function() use ($entry, $handles, $category, &$log) {
            $entry->off(Element::EVENT_AFTER_SAVE, $log);
            Craft::info(sprintf('Primer filled %s in %s.', implode(', ', $handles), $this->describe($entry)), $category);
        };
        $entry->on(Element::EVENT_AFTER_SAVE, $log);
    }

    private function describe(Entry $entry): string
    {
        return sprintf(
            '%s (site %s, entry type %s)',
            $entry->id ? "entry $entry->id" : 'a new entry',
            $entry->getSite()->handle,
            $entry->getType()->handle,
        );
    }

    /**
     * The entry's layout element with the preset's UID, if it is still a relation field there.
     */
    private function relationFieldElement(FieldLayout $layout, string $uid): ?CustomField
    {
        $element = $layout->getElementByUid($uid);

        return $element instanceof CustomField && Preset::isRelationField($element->getField()) ? $element : null;
    }

    /**
     * The preset's element IDs for a field, in preset order, as many as the entry's field allows.
     *
     * @return int[]
     */
    private function ids(mixed $value, CustomField $target): array
    {
        if (!$value instanceof ElementQueryInterface) {
            return [];
        }

        $field = $target->getField();
        $maximum = $field instanceof BaseRelationField ? $field->maxRelations : null;

        return Truncation::toMaximum($value->status(null)->ids(), $maximum);
    }

    private function preset(int $sectionId, int $typeId, int $siteId): ?Preset
    {
        $key = "$sectionId:$typeId:$siteId";

        $this->_presets[$key] ??= Preset::find()
            ->sectionId($sectionId)
            ->typeId($typeId)
            ->siteId($siteId)
            ->one() ?? false;

        // The preset of an entry type removed from its section is inert.
        if ($this->_presets[$key] && !$this->_presets[$key]->typeIsInSection()) {
            $this->_presets[$key] = false;
        }

        return $this->_presets[$key] ?: null;
    }

    private function storedTypeId(Entry $entry): ?int
    {
        $typeId = (new Query())
            ->select('typeId')
            ->from(Table::ENTRIES)
            ->where(['id' => $entry->id])
            ->scalar();

        return is_numeric($typeId) ? (int)$typeId : null;
    }

    private function typeId(Entry $entry): ?int
    {
        try {
            return $entry->getTypeId();
        } catch (InvalidConfigException) {
            return null;
        }
    }
}
