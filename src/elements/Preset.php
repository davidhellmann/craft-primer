<?php

namespace davidhellmann\primer\elements;

use CraftCms\Cms\Component\Contracts\Colorable;
use CraftCms\Cms\Component\Contracts\Iconic;
use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Element\Actions\Duplicate;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Element;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Shared\Enums\Color;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Url;
use CraftCms\RulesetValidation\Attributes\Ruleset;
use davidhellmann\primer\decisions\FieldChoice;
use davidhellmann\primer\decisions\Scope;
use davidhellmann\primer\elements\db\PresetQuery;
use davidhellmann\primer\fieldlayoutelements\EntryTypeField;
use davidhellmann\primer\fieldlayoutelements\FieldChoiceField;
use davidhellmann\primer\fieldlayoutelements\SectionField;
use davidhellmann\primer\holdable\HoldableField;
use davidhellmann\primer\holdable\RelationField;
use davidhellmann\primer\models\Preset as PresetModel;
use davidhellmann\primer\services\Presets;
use davidhellmann\primer\services\PresetValues;
use davidhellmann\primer\validation\PresetRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator as LaravelValidator;
use Override;

use function CraftCms\Cms\t;

/**
 * The starting elements for the chosen relation fields of one entry type in one section.
 *
 * Its field layout is built from the entry type's chosen fields, with the same layout element UIDs, so Craft stores,
 * relates and propagates a preset's values exactly as it does an entry's, and they map one to one. Craft's own element
 * editor edits it; until it's saved for the first time, the layout starts with its section and entry type.
 *
 * The presets of an entry type, one per scope, share their field choice and their fate: every save and delete keeps
 * them so, whoever saves or deletes, so the rules hold for Craft's own saves too.
 *
 * @property string[] $fieldUids
 */
/** @phpstan-ignore argument.type (Craft's own element types declare their rulesets the same way.) */
#[Ruleset(PresetRules::class)]
class Preset extends Element implements Colorable, Iconic
{
    public const TABLE = 'primer_presets';

    public ?int $sectionId = null;
    public ?int $typeId = null;

    /**
     * @var string[] The chosen layout element UIDs.
     */
    private array $_fieldUids = [];

    /**
     * @var string[]|null The field choice the entry type's presets shared before the save in progress.
     */
    private ?array $_choiceBefore = null;

    private ?FieldLayout $_fieldLayout = null;
    private ?string $_fieldLayoutKey = null;

    #[Override]
    public static function displayName(): string
    {
        return t('Preset', category: 'primer');
    }

    #[Override]
    public static function lowerDisplayName(): string
    {
        return t('preset', category: 'primer');
    }

    #[Override]
    public static function pluralDisplayName(): string
    {
        return t('Presets', category: 'primer');
    }

    #[Override]
    public static function pluralLowerDisplayName(): string
    {
        return t('presets', category: 'primer');
    }

    #[Override]
    public static function hasTitles(): bool
    {
        return false;
    }

    #[Override]
    public static function isLocalized(): bool
    {
        return true;
    }

    #[Override]
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * Every preset, then one source per section with entry types. Each lists the presets of the site the index is
     * about.
     */
    #[Override]
    protected static function defineSources(string $context): array
    {
        $sources = [[
            'key' => '*',
            'label' => t('All presets', category: 'primer'),
            'criteria' => ['inSection' => true],
        ]];

        $sections = Sections::getAllSections()->filter(fn(Section $section) => $section->getEntryTypes() !== []);
        if ($sections->isNotEmpty()) {
            $sources[] = ['heading' => t('Sections')];

            foreach ($sections as $section) {
                $sources[] = [
                    'key' => "section:$section->uid",
                    'label' => t($section->name, category: 'site'),
                    'criteria' => ['sectionId' => $section->id, 'inSection' => true],
                    'data' => ['handle' => $section->handle],
                ];
            }
        }

        return $sources;
    }

    /**
     * Craft's actions, without Duplicate: an entry type has one preset per site.
     */
    #[Override]
    public static function actions(string $source): array
    {
        return array_values(array_filter(parent::actions($source), fn($action) => $action !== Duplicate::class));
    }

    #[Override]
    protected static function includeSetStatusAction(): bool
    {
        return true;
    }

    #[Override]
    protected static function defineTableAttributes(): array
    {
        return [
            'section' => ['label' => t('Section')],
            'fields' => ['label' => t('Fields')],
            ...parent::defineTableAttributes(),
        ];
    }

    #[Override]
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return $source === '*' ? ['section', 'fields', 'status'] : ['fields', 'status'];
    }

    #[Override]
    protected static function defineSortOptions(): array
    {
        return [
            ['label' => t('Date Created'), 'orderBy' => 'dateCreated', 'defaultDir' => 'desc'],
            ['label' => t('Date Updated'), 'orderBy' => 'dateUpdated', 'defaultDir' => 'desc'],
            ['label' => t('ID'), 'orderBy' => 'id'],
        ];
    }

    #[Override]
    protected function attributeHtml(string $attribute): string|\Stringable
    {
        return match ($attribute) {
            'section' => Html::encode(($section = $this->getSection()) ? t($section->name, category: 'site') : ''),
            'fields' => Html::encode(implode(', ', array_map(
                fn(CustomField $element) => (string)$element->label(),
                $this->getChosenFieldElements(),
            )) ?: t('No fields yet', category: 'primer')),
            default => parent::attributeHtml($attribute),
        };
    }

    /**
     * The entry type's own icon, which the preset's chips and cards show.
     */
    public function getIcon(): ?string
    {
        return $this->getType()?->getIcon();
    }

    /**
     * The entry type's own colour.
     */
    public function getColor(): ?Color
    {
        return $this->getType()?->getColor();
    }

    /**
     * @return PresetQuery
     */
    #[Override]
    public static function find(): PresetQuery
    {
        return new PresetQuery();
    }

    /**
     * Whether the preset applies in its site: on, and on for that site.
     */
    public function isOn(): bool
    {
        return $this->enabled && (bool)$this->getEnabledForSite();
    }

    /**
     * Whether the preset hasn't been saved yet: an unpublished draft, which picks its section and entry type.
     */
    public function isNewPreset(): bool
    {
        return !$this->id || $this->getIsUnpublishedDraft();
    }

    /**
     * @return string[]
     */
    public function getFieldUids(): array
    {
        return $this->_fieldUids;
    }

    /**
     * @param string[]|string|null $uids The chosen layout element UIDs, or their JSON as stored.
     */
    public function setFieldUids(array|string|null $uids): void
    {
        if (is_string($uids)) {
            $uids = $uids === '' ? [] : Json::decodeIfJson($uids);
        }

        $this->_fieldUids = array_values(array_unique(array_filter((array)$uids, 'is_string')));
    }

    /**
     * Only what the preset's own fields post: its section and entry type while it's new, and its field choice. A new
     * preset that gets another entry type keeps the fields of the choice that are the entry type's, or else starts
     * with the field choice the entry type's presets in other scopes share.
     */
    #[Override]
    public function setAttributesFromRequest(array $values): void
    {
        $typeBefore = [$this->sectionId, $this->typeId];

        if ($this->isNewPreset()) {
            foreach (['sectionId', 'typeId'] as $attribute) {
                if (is_numeric($values[$attribute] ?? null)) {
                    $this->$attribute = (int)$values[$attribute];
                }
            }

            // A section without this entry type takes its first one.
            if ($this->sectionId && !$this->typeIsInSection()) {
                $this->typeId = ($this->getSection()?->getEntryTypes()[0] ?? null)?->id;
            }
        }

        if (array_key_exists('fieldUids', $values)) {
            $this->setFieldUids($values['fieldUids'] ?? []);
        }

        // Layout element UIDs are the entry type's own: a choice for another one keeps nothing.
        if ([$this->sectionId, $this->typeId] !== $typeBefore) {
            $kept = FieldChoice::kept($this->_fieldUids, $this->getLayoutFieldUids());
            $this->_fieldUids = $kept ?: app(Presets::class)->sharedChoice((int)$this->sectionId, (int)$this->typeId);
        }
    }

    public function getSection(): ?Section
    {
        return $this->sectionId ? Sections::getSectionById($this->sectionId) : null;
    }

    public function getType(): ?EntryType
    {
        return $this->typeId ? EntryTypes::getEntryTypeById($this->typeId) : null;
    }

    /**
     * The preset's scope: the sites a new entry of its section, created in the preset's site, would be saved to, but
     * none another preset of the section and entry type holds, so every site has one preset.
     */
    #[Override]
    public function getSupportedSites(): array
    {
        $siteId = $this->siteIdOrPrimary();
        $section = $this->getSection();

        $sectionSites = [];
        foreach (array_keys($section?->getSiteSettings() ?? []) as $id) {
            $site = Sites::getSiteById($id, true);
            if ($site) {
                $sectionSites[$site->id] = ['groupId' => $site->groupId, 'language' => $site->language];
            }
        }

        $siteIds = $section && $this->typeId
            ? Scope::siteIds($section->propagationMethod, $siteId, $sectionSites, $this->sitesOfOtherPresets())
            : [];

        // Without a section, or in a site the section isn't enabled for, the save fails validation;
        // Craft needs a supported site to get there.
        return array_map(fn(int $id) => ['siteId' => $id, 'propagate' => true], $siteIds ?: [$siteId]);
    }

    /**
     * The sites the other presets of the section and entry type hold, and this one doesn't. Drafts hold none.
     *
     * @return int[]
     */
    private function sitesOfOtherPresets(): array
    {
        if (!$this->sectionId || !$this->typeId) {
            return [];
        }

        $rows = DB::table(Table::ELEMENTS_SITES, 'elements_sites')
            ->join(self::TABLE.' as primer_presets', 'primer_presets.id', '=', 'elements_sites.elementId')
            ->join(Table::ELEMENTS.' as elements', 'elements.id', '=', 'elements_sites.elementId')
            ->where('primer_presets.sectionId', $this->sectionId)
            ->where('primer_presets.typeId', $this->typeId)
            ->whereNull('elements.draftId')
            ->whereNull('elements.revisionId')
            ->whereNull('elements.dateDeleted')
            ->get(['elements_sites.elementId', 'elements_sites.siteId']);

        $others = [];
        $own = [];
        foreach ($rows as $row) {
            if ((int)$row->elementId === $this->id) {
                $own[] = (int)$row->siteId;
            } else {
                $others[] = (int)$row->siteId;
            }
        }

        return array_values(array_diff($others, $own));
    }

    /**
     * The preset's site; a preset made without one is made in the primary site, as Craft does.
     */
    private function siteIdOrPrimary(): int
    {
        return $this->siteId ?? Sites::getPrimarySite()->id;
    }

    /**
     * The entry type's chosen holdable fields (`HoldableField`), with the same layout element UIDs, not required,
     * without conditions, in one tab, after the field choice; a new preset's starts with its section and entry type.
     * Built, not stored: it follows the entry type's layout and the choice.
     */
    #[Override]
    public function getFieldLayout(): ?FieldLayout
    {
        $key = Json::encode([$this->sectionId, $this->typeId, $this->_fieldUids, $this->isNewPreset()]);

        if ($this->_fieldLayoutKey !== $key) {
            $this->_fieldLayout = $this->buildFieldLayout();
            $this->_fieldLayoutKey = $key;
        }

        return $this->_fieldLayout;
    }

    /**
     * The holdable fields of the entry type's layout, chosen or not, in layout order, as layout elements of the
     * preset.
     *
     * @return CustomField[]
     */
    public function getLayoutFieldElements(): array
    {
        $source = $this->getType()?->getFieldLayout();
        if (!$source) {
            return [];
        }

        $elements = [];
        foreach ($source->getCustomFieldElements() as $element) {
            if (!HoldableField::of($element)) {
                continue;
            }

            $config = array_merge($element->toArray(), [
                'required' => false,
                'width' => 100,
                'userCondition' => null,
                'elementCondition' => null,
                'editCondition' => null,
                'elementEditCondition' => null,
            ]);
            $elements[] = new CustomField($element->getField(), $config);
        }

        return $elements;
    }

    /**
     * The chosen fields that are still in the entry type's layout, in layout order.
     *
     * @return CustomField[]
     */
    public function getChosenFieldElements(): array
    {
        return array_values(array_filter(
            $this->getFieldLayout()?->getCustomFieldElements() ?? [],
            fn(CustomField $element) => in_array($element->uid, $this->_fieldUids, true),
        ));
    }

    /**
     * The UIDs of the holdable fields of the entry type's layout, in layout order.
     *
     * @return string[]
     */
    public function getLayoutFieldUids(): array
    {
        return array_map(fn(CustomField $element) => (string)$element->uid, $this->getLayoutFieldElements());
    }

    private function buildFieldLayout(): FieldLayout
    {
        $elements = [];

        if ($this->isNewPreset()) {
            $elements[] = new SectionField();
            $elements[] = new EntryTypeField();
        }

        if ($this->getType()) {
            $elements[] = new FieldChoiceField();
        }

        foreach ($this->getLayoutFieldElements() as $element) {
            if (in_array($element->uid, $this->_fieldUids, true)) {
                $elements[] = $element;
            }
        }

        $layout = FieldLayout::createFromConfig(['type' => self::class]);
        $layout->uid = '6f0d8b4e-3c7a-4b1e-9a52-7d1c2e8f4a90';
        $layout->setTabs([[
            'name' => t('Preset', category: 'primer'),
            'uid' => '2b9e4c1d-8f3a-4e6b-b7d2-5a1c9e0f3b48',
            'elements' => $elements,
        ]]);

        return $layout;
    }

    /**
     * Whether the entry type is one of the section's. A preset of a type removed from its section stays, hidden and
     * inert, and returns when the type is added back.
     */
    public function typeIsInSection(): bool
    {
        $typeIds = array_map(fn(EntryType $type) => $type->id, $this->getSection()?->getEntryTypes() ?? []);

        return in_array($this->typeId, $typeIds, true);
    }

    #[Override]
    protected function uiLabel(): ?string
    {
        $type = $this->getType();

        return $type ? t($type->name, category: 'site') : t('New preset', category: 'primer');
    }

    #[Override]
    protected function cpEditUrl(): ?string
    {
        return "primer/presets/$this->id";
    }

    #[Override]
    public function getPostEditUrl(): ?string
    {
        return Url::cpUrl('primer');
    }

    /**
     * Primer, then the preset's section, whose source in Primer's index lists its presets.
     */
    #[Override]
    protected function crumbs(): array
    {
        $crumbs = [
            new ActionItem()
                ->label(t('Primer', category: 'primer'))
                ->href(Url::cpUrl('primer')),
        ];

        $section = $this->getSection();
        if ($section) {
            $crumbs[] = new ActionItem()
                ->label(t($section->name, category: 'site'))
                ->href(Url::cpUrl('primer', ['source' => "section:$section->uid"]));
        }

        return $crumbs;
    }

    /**
     * What the preset is for, in the editor's sidebar, once it's saved. It doesn't change.
     */
    #[Override]
    protected function metadata(): array
    {
        if ($this->isNewPreset()) {
            return [];
        }

        return [
            t('Section') => fn() => ($section = $this->getSection()) ? t($section->name, category: 'site') : false,
            t('Entry Type') => fn() => ($type = $this->getType()) ? t($type->name, category: 'site') : false,
        ];
    }

    /**
     * The minimum and maximum number of relations of each chosen field, and of the relation fields of a chosen Content
     * Block, whose form then shows the error at the field. Craft checks the chosen fields itself in its live scenario.
     */
    #[Override]
    public function afterValidate(?LaravelValidator $validator = null): void
    {
        parent::afterValidate($validator);

        foreach ($this->getChosenFieldElements() as $element) {
            $field = HoldableField::of($element);
            if ($field instanceof RelationField && $this->ruleset->inScenarios(PresetRules::SCENARIO_LIVE)) {
                continue;
            }

            $field?->validateIn($this);
        }
    }

    #[Override]
    public function beforeSave(bool $isNew): bool
    {
        // A chosen field that left the entry type's layout is dropped from the choice.
        if ($this->getType()) {
            $this->_fieldUids = FieldChoice::kept($this->_fieldUids, $this->getLayoutFieldUids());
        }

        return parent::beforeSave($isNew);
    }

    #[Override]
    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $this->_choiceBefore = $this->sharedChoiceBefore($isNew);

            $model = PresetModel::query()->find($this->id) ?? new PresetModel(['id' => $this->id]);
            $model->sectionId = (int)$this->sectionId;
            $model->typeId = (int)$this->typeId;
            $model->fieldUids = $this->_fieldUids;
            $model->save();

            // The field choice is the same in every scope: the other scopes' presets of the entry type follow.
            if (!$this->getIsDraft()) {
                PresetModel::query()
                    ->where('sectionId', $this->sectionId)
                    ->where('typeId', $this->typeId)
                    ->whereKeyNot($this->id)
                    ->update(['fieldUids' => Json::encode($this->_fieldUids)]);
            }
        }

        parent::afterSave($isNew);
    }

    /**
     * Once the preset is saved in every site of its scope: a field the save took out of the choice is emptied in every
     * site of every scope, so a field chosen again starts empty.
     */
    #[Override]
    public function afterPropagate(bool $isNew): void
    {
        parent::afterPropagate($isNew);

        $before = $this->_choiceBefore;
        $this->_choiceBefore = null;

        $removed = $before !== null ? FieldChoice::removed($before, $this->_fieldUids, $this->getLayoutFieldUids()) : [];
        if (!$removed || $this->getIsDraft()) {
            return;
        }

        $fieldIds = [];
        foreach ($this->getLayoutFieldElements() as $element) {
            if (in_array($element->uid, $removed, true)) {
                $fieldIds[] = (int)$element->getField()->id;
            }
        }

        app(PresetValues::class)->forget(
            app(Presets::class)->ids((int)$this->sectionId, (int)$this->typeId),
            array_values(array_unique($fieldIds)),
            $removed,
        );
    }

    /**
     * Presets have no trash: every delete is for good, also Craft's soft delete, its default.
     */
    #[Override]
    public function beforeDelete(): bool
    {
        $this->hardDelete = true;

        return parent::beforeDelete();
    }

    /**
     * The presets of the entry type in every other scope go too, also in sites the user can't edit.
     */
    #[Override]
    public function afterDelete(): void
    {
        parent::afterDelete();

        if (!$this->sectionId || !$this->typeId || $this->getIsDraft()) {
            return;
        }

        $others = self::find()
            ->sectionId($this->sectionId)
            ->typeId($this->typeId)
            ->id(['not', $this->id])
            ->site('*')
            ->unique()
            ->all();

        foreach ($others as $other) {
            Elements::deleteElement($other, true);
        }
    }

    /**
     * The field choice the entry type's presets shared before this save: this preset's, or, for a new one, the other
     * scopes'.
     *
     * @return string[]
     */
    private function sharedChoiceBefore(bool $isNew): array
    {
        $query = PresetModel::query()
            ->where('sectionId', $this->sectionId)
            ->where('typeId', $this->typeId)
            ->orderBy('id');

        if (!$isNew) {
            $query->whereKey($this->id);
        }

        $fieldUids = $query->value('fieldUids');
        $fieldUids = is_string($fieldUids) ? Json::decodeIfJson($fieldUids) : $fieldUids;

        return array_values(array_filter((array)$fieldUids, 'is_string'));
    }

    /**
     * The section is enabled for the preset's site, used by `PresetRules`.
     */
    public function sectionIsEnabledForSite(): bool
    {
        return isset($this->getSection()?->getSiteSettings()[$this->siteIdOrPrimary()]);
    }

    /**
     * Whether another preset of the section and entry type holds a site this one would span, used by `PresetRules`.
     */
    public function overlapsAnotherPreset(): bool
    {
        $query = self::find()
            ->sectionId($this->sectionId)
            ->typeId($this->typeId)
            ->siteId(array_column(ElementHelper::supportedSitesForElement($this), 'siteId'));

        if ($this->id) {
            $query->id(['not', $this->getCanonicalId() ?? $this->id]);
        }

        return $query->exists();
    }

    /**
     * The section and entry type stored for a saved preset, used by `PresetRules`: a saved preset keeps them, also
     * when Craft's own `elements/save` is posted others.
     *
     * @return array{sectionId: int, typeId: int}|null
     */
    public function storedSectionAndType(): ?array
    {
        if (!$this->id || $this->getIsUnpublishedDraft()) {
            return null;
        }

        $model = PresetModel::query()->find($this->getCanonicalId() ?? $this->id);

        return $model ? ['sectionId' => $model->sectionId, 'typeId' => $model->typeId] : null;
    }
}
