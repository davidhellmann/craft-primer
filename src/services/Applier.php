<?php

namespace davidhellmann\primer\services;

use Craft;
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
 * Fills the empty chosen relation fields of an entry that is being created, from the preset of its section,
 * entry type and site (spec section 5). Craft then saves and propagates the entry as usual.
 */
class Applier extends Component
{
    /**
     * @var array<string, Preset|false> Presets by section, entry type and site, for this request.
     */
    private array $presets = [];

    /**
     * Handles an entry's `beforeSave`. Never stops the save: a failure is logged and the entry saves without.
     */
    public function apply(Entry $entry, bool $isNew): void
    {
        $typeId = $this->typeId($entry);

        $apply = ApplyDecision::shouldApply(
            isNew: $isNew,
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
            $this->fill($entry, $typeId);
        } catch (Throwable $e) {
            Craft::warning("Primer couldn't apply the preset to an entry of entry type $typeId: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
        }
    }

    /**
     * Forgets the presets loaded so far, after one was saved or deleted.
     */
    public function reset(): void
    {
        $this->presets = [];
    }

    private function fill(Entry $entry, int $typeId): void
    {
        $preset = $this->preset((int)$entry->sectionId, $typeId, $entry->siteId);
        $layout = $entry->getFieldLayout();

        // Presets have no statuses, so the query returned it whatever its state.
        if (!$preset || !$preset->enabled || !$preset->getEnabledForSite() || !$layout) {
            return;
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

        if ($filled) {
            Craft::info(sprintf('Primer filled %s from preset %d.', implode(', ', $filled), $preset->id), __METHOD__);
        }
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

        $this->presets[$key] ??= Preset::find()
            ->sectionId($sectionId)
            ->typeId($typeId)
            ->siteId($siteId)
            ->one() ?? false;

        return $this->presets[$key] ?: null;
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
