<?php

namespace davidhellmann\primer\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use davidhellmann\primer\decisions\ApplyDecision;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\holdable\HoldableField;
use davidhellmann\primer\Plugin;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Fills the empty chosen fields of an entry that is being created or switched to another entry type, also the
 * relation fields inside a chosen Content Block, from the preset of its section, entry type and site (spec section 5).
 * Craft then saves and propagates the entry as usual.
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
        try {
            $filled = $this->fillIfDue($entry, $isNew);
        } catch (Throwable $e) {
            Craft::warning(sprintf("Primer couldn't apply the preset to %s: %s", $this->describe($entry), $e), __METHOD__);
            return;
        }

        if ($filled) {
            $this->logOnceSaved($entry, $filled);
        }
    }

    /**
     * Forgets the presets loaded so far, after one was saved or deleted or a field layout changed.
     */
    public function reset(): void
    {
        $this->_presets = [];
    }

    /**
     * Fills the entry from its preset if the save is one that applies it (`ApplyDecision`).
     *
     * @return string[] The handles of the fields it filled.
     */
    private function fillIfDue(Entry $entry, bool $isNew): array
    {
        // `Entry::setTypeId()` empties `fieldLayoutId`, and Craft writes it back only after `beforeSave`.
        $typeSet = $entry->id && $entry->fieldLayoutId === null;

        $typeId = ApplyDecision::typeToApply(
            isNew: $isNew,
            typeSet: $typeSet,
            typeId: $this->typeId($entry),
            // One query, only when the type was set.
            storedTypeId: !$isNew && $typeSet ? $this->storedTypeId($entry) : null,
            hasSection: $entry->sectionId !== null,
            propagating: $entry->propagating,
            resaving: $entry->resaving,
            duplicate: $entry->duplicateOf !== null,
            updatingFromDerivative: $entry->updatingFromDerivative,
            mergingCanonicalChanges: $entry->mergingCanonicalChanges,
            revision: $entry->getIsRevision(),
        );

        return $typeId !== null ? $this->fill($entry, $typeId) : [];
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
        if (!$preset || !$preset->isOn() || !$layout) {
            return [];
        }

        $filled = [];
        foreach ($preset->getChosenFieldElements() as $source) {
            // The entry's layout element with the preset's UID, if it is still a field a preset can hold.
            $target = $layout->getElementByUid($source->uid);
            $field = $target instanceof CustomField ? HoldableField::of($target) : null;

            if ($field?->fill($entry, $preset->getFieldValue($source->attribute()))) {
                $filled[] = $field->handle();
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

    /**
     * The entry for a log line. Also for a failure, so it reads only what can't throw.
     */
    private function describe(Entry $entry): string
    {
        $typeId = $this->typeId($entry);
        $site = Craft::$app->getSites()->getSiteById($entry->siteId, true);
        $type = $typeId ? Craft::$app->getEntries()->getEntryTypeById($typeId) : null;

        return sprintf(
            '%s (site %s, entry type %s)',
            $entry->id ? "entry $entry->id" : 'a new entry',
            $site->handle ?? $entry->siteId,
            $type->handle ?? '?',
        );
    }

    private function preset(int $sectionId, int $typeId, int $siteId): ?Preset
    {
        $key = "$sectionId:$typeId:$siteId";
        $this->_presets[$key] ??= Plugin::getInstance()->presets->inSite($sectionId, $typeId, $siteId) ?? false;

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
