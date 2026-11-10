<?php

namespace davidhellmann\primer\services;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Events\ElementLifecycleSaved;
use CraftCms\Cms\Element\Events\ElementLifecycleSaving;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Sites;
use davidhellmann\primer\decisions\ApplyDecision;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\holdable\HoldableField;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * Fills the empty chosen fields of an entry that is being created or switched to another entry type, also the
 * relation fields inside a chosen Content Block, from the preset of its section, entry type and site.
 * Craft then saves and propagates the entry as usual.
 */
#[Singleton]
class Applier
{
    /**
     * @var array<string, Preset|false> Presets by section, entry type and site, until one changes.
     */
    private array $presets = [];

    /**
     * @var WeakMap<Entry, string[]> The fields filled in entries being saved, for their log line once they're saved.
     */
    private WeakMap $filled;

    public function __construct(
        private readonly Presets $presetsService,
    ) {
        $this->filled = new WeakMap();
    }

    /**
     * Handles an entry's save, before Craft writes it. Never stops the save: a failure is logged and the entry saves
     * without.
     */
    public function handleSaving(ElementLifecycleSaving $event): void
    {
        $entry = $event->element;
        if (!$entry instanceof Entry) {
            return;
        }

        try {
            $filled = $this->fillIfDue($entry, $event->isNew);
        } catch (Throwable $e) {
            Log::warning(sprintf("Primer couldn't apply the preset to %s: %s", $this->describe($entry), $e));

            return;
        }

        if ($filled) {
            $this->filled[$entry] = $filled;
        }
    }

    /**
     * One info line per filled entry, written once it is saved: a new entry has no ID before.
     */
    public function handleSaved(ElementLifecycleSaved $event): void
    {
        $entry = $event->element;
        if (!$entry instanceof Entry || !isset($this->filled[$entry])) {
            return;
        }

        $handles = $this->filled[$entry];
        unset($this->filled[$entry]);
        Log::info(sprintf('Primer filled %s in %s.', implode(', ', $handles), $this->describe($entry)));
    }

    /**
     * Forgets the presets loaded so far, after one was saved or deleted or a field layout changed.
     */
    public function reset(): void
    {
        $this->presets = [];
    }

    /**
     * Fills the entry from its preset if the save is one that applies it (`ApplyDecision`).
     *
     * @return string[] The handles of the fields it filled.
     */
    private function fillIfDue(Entry $entry, bool $isNew): array
    {
        // `Entry::setTypeId()` empties `fieldLayoutId`, and Craft writes it back only after `beforeSave()`.
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
        $preset = $this->preset((int)$entry->sectionId, $typeId, (int)$entry->siteId);
        $layout = $entry->getFieldLayout();

        // The query returns presets whatever their status.
        if (!$preset || !$preset->isOn() || !$layout) {
            return [];
        }

        $filled = [];
        foreach ($preset->getChosenFieldElements() as $source) {
            // The entry's layout element with the preset's UID, if it is still a field a preset can hold.
            $target = $layout->getElementByUid((string)$source->uid);
            $field = $target instanceof CustomField ? HoldableField::of($target) : null;

            if ($field?->fill($entry, $preset->getFieldValue($source->attribute()))) {
                $filled[] = $field->handle();
            }
        }

        return $filled;
    }

    /**
     * The entry for a log line. Also for a failure, so it reads only what can't throw.
     */
    private function describe(Entry $entry): string
    {
        $typeId = $this->typeId($entry);
        $site = $entry->siteId ? Sites::getSiteById($entry->siteId, true) : null;
        $type = $typeId ? EntryTypes::getEntryTypeById($typeId) : null;

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
        $this->presets[$key] ??= $this->presetsService->inSite($sectionId, $typeId, $siteId) ?? false;

        return $this->presets[$key] ?: null;
    }

    private function storedTypeId(Entry $entry): ?int
    {
        $typeId = DB::table(Table::ENTRIES)->where('id', $entry->id)->value('typeId');

        return is_numeric($typeId) ? (int)$typeId : null;
    }

    private function typeId(Entry $entry): ?int
    {
        try {
            return $entry->getTypeId();
        } catch (Throwable) {
            return null;
        }
    }
}
