<?php

namespace davidhellmann\primer\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use davidhellmann\primer\elements\Preset;

/**
 * Finds presets by section and entry type.
 *
 * Presets have no statuses (`Preset::hasStatuses()` is false), so `status()` changes nothing:
 * check `enabled` and `getEnabledForSite()` on the preset.
 *
 * @extends ElementQuery<int, Preset>
 */
class PresetQuery extends ElementQuery
{
    public mixed $sectionId = null;
    public mixed $typeId = null;

    /**
     * Oldest first: should two presets of an entry type share a site anyway, `one()` gets the one that applies.
     */
    protected array $defaultOrderBy = [
        'elements.dateCreated' => SORT_ASC,
        'elements.id' => SORT_ASC,
    ];

    public function sectionId(mixed $value): static
    {
        $this->sectionId = $value;
        return $this;
    }

    public function typeId(mixed $value): static
    {
        $this->typeId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable(Preset::TABLE);

        $this->query->addSelect([
            'primer_presets.sectionId',
            'primer_presets.typeId',
            'primer_presets.fieldUids',
        ]);

        if ($this->sectionId) {
            $this->subQuery->andWhere(Db::parseNumericParam('primer_presets.sectionId', $this->sectionId));
        }

        if ($this->typeId) {
            $this->subQuery->andWhere(Db::parseNumericParam('primer_presets.typeId', $this->typeId));
        }

        return parent::beforePrepare();
    }
}
