<?php

namespace davidhellmann\primer\elements\db;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Queries\ElementQuery;
use davidhellmann\primer\elements\Preset;
use Illuminate\Database\Query\Builder;
use Override;

/**
 * Finds presets by section and entry type.
 *
 * Unlike most element queries, it finds presets whatever their status: check `Preset::isOn()` for the one that
 * applies. Ask for a status with `status()`, as Primer's index does.
 *
 * @extends ElementQuery<Preset>
 */
class PresetQuery extends ElementQuery
{
    #[Override]
    protected string $table = Preset::TABLE;

    /**
     * Oldest first: should two presets of an entry type share a site anyway, `one()` gets the one that applies.
     *
     * @var array<string, int>
     */
    #[Override]
    protected array $defaultOrderBy = [
        'elements.dateCreated' => SORT_ASC,
        'elements.id' => SORT_ASC,
    ];

    #[Override]
    public array|string|null $status = null;

    public mixed $sectionId = null;
    public mixed $typeId = null;

    /**
     * @var bool Whether to leave out presets whose entry type has left their section. They stay, hidden and inert,
     * until it's added back.
     */
    public bool $inSection = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct(Preset::class, $config);

        $this->query->addSelect([
            'primer_presets.sectionId as sectionId',
            'primer_presets.typeId as typeId',
            'primer_presets.fieldUids as fieldUids',
        ]);

        $this->beforeQuery(static function(self $query) {
            if ($query->sectionId) {
                $query->whereNumericParam('primer_presets.sectionId', $query->sectionId);
            }

            if ($query->typeId) {
                $query->whereNumericParam('primer_presets.typeId', $query->typeId);
            }

            if ($query->inSection) {
                $query->whereExists(fn(Builder $sub) => $sub
                    ->from(Table::SECTIONS_ENTRYTYPES)
                    ->whereColumn('sectionId', 'primer_presets.sectionId')
                    ->whereColumn('typeId', 'primer_presets.typeId'));
            }
        });
    }

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

    public function inSection(bool $value = true): static
    {
        $this->inSection = $value;

        return $this;
    }
}
