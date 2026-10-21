<?php

namespace davidhellmann\primer\elements;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use davidhellmann\primer\decisions\FieldChoice;
use davidhellmann\primer\decisions\Scope;
use davidhellmann\primer\decisions\Status;
use davidhellmann\primer\elements\db\PresetQuery;
use davidhellmann\primer\holdable\HoldableField;
use davidhellmann\primer\Plugin;

/**
 * The starting elements for the chosen relation fields of one entry type in one section.
 *
 * Its field layout is built from the entry type's, with the same layout element UIDs, so Craft stores,
 * relates and propagates a preset's values exactly as it does an entry's, and they map one to one.
 *
 * The presets of an entry type, one per scope, share their field choice and their fate: every save and delete keeps
 * them so, whoever saves or deletes, so the rules hold for Craft's own saves too.
 *
 * @property string[] $fieldUids
 */
class Preset extends Element
{
    public const TABLE = '{{%primer_presets}}';

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
    private ?int $_fieldLayoutTypeId = null;

    public static function displayName(): string
    {
        return Craft::t('primer', 'Preset');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('primer', 'preset');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('primer', 'Presets');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('primer', 'presets');
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * No "Copy value from site" in a field's menu: Craft's copy runs in its element editor's script, which the
     * preset screen doesn't use.
     */
    public function getIsCrossSiteCopyable(): bool
    {
        return false;
    }

    /**
     * @return PresetQuery
     */
    public static function find(): PresetQuery
    {
        return new PresetQuery(static::class);
    }

    /**
     * Whether the preset applies in its site: on, and on for that site (spec 4.6). Presets have no statuses in
     * Craft's sense, so a query's `status()` can't tell.
     */
    public function isOn(): bool
    {
        return $this->enabled && $this->getEnabledForSite();
    }

    /**
     * Switches sites of the preset's scope on or off, as Craft's status field does (`Status::switched()`); other
     * sites are left out.
     *
     * @param array<int, bool> $bySite
     * @return int[] The sites whose status this sets. More than the preset's own site, and the save has to propagate.
     */
    public function switchSites(array $bySite): array
    {
        $switched = Status::switched($this->enabled, $bySite, array_column($this->getSupportedSites(), 'siteId'));
        $this->enabled = $switched['enabled'];
        $this->setEnabledForSite($switched['sites']);

        return array_keys($switched['sites']);
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
            $uids = Json::decodeIfJson($uids);
        }

        $this->_fieldUids = array_values(array_unique(array_filter((array)$uids, 'is_string')));
    }

    public function getSection(): ?Section
    {
        return $this->sectionId ? Craft::$app->getEntries()->getSectionById($this->sectionId) : null;
    }

    public function getType(): ?EntryType
    {
        return $this->typeId ? Craft::$app->getEntries()->getEntryTypeById($this->typeId) : null;
    }

    /**
     * The preset's scope: the sites a new entry of its section, created in the preset's site, would be saved to, but
     * none another preset of the section and entry type holds, so every site has one preset.
     */
    public function getSupportedSites(): array
    {
        $sites = Craft::$app->getSites();
        $siteId = $this->siteIdOrPrimary();
        $section = $this->getSection();

        $sectionSites = [];
        foreach (array_keys($section?->getSiteSettings() ?? []) as $id) {
            $site = $sites->getSiteById($id, true);
            if ($site) {
                $sectionSites[$site->id] = ['groupId' => $site->groupId, 'language' => $site->language];
            }
        }

        $siteIds = $section
            ? Scope::siteIds($section->propagationMethod, $siteId, $sectionSites, $this->sitesOfOtherPresets())
            : [];

        // Without a section, or in a site the section isn't enabled for, the save fails validation;
        // Craft needs a supported site to get there.
        return array_map(fn(int $id) => ['siteId' => $id, 'propagate' => true], $siteIds ?: [$siteId]);
    }

    /**
     * The sites the other presets of the section and entry type hold, and this one doesn't.
     *
     * @return int[]
     */
    private function sitesOfOtherPresets(): array
    {
        if (!$this->sectionId || !$this->typeId) {
            return [];
        }

        $rows = (new Query())
            ->select(['elements_sites.elementId', 'elements_sites.siteId'])
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['primer_presets' => self::TABLE], '[[primer_presets.id]] = [[elements_sites.elementId]]')
            ->where(['primer_presets.sectionId' => $this->sectionId, 'primer_presets.typeId' => $this->typeId])
            ->all();

        $others = [];
        $own = [];
        foreach ($rows as $row) {
            if ((int)$row['elementId'] === $this->id) {
                $own[] = (int)$row['siteId'];
            } else {
                $others[] = (int)$row['siteId'];
            }
        }

        return array_values(array_diff($others, $own));
    }

    /**
     * The preset's site; a preset made without one is made in the primary site, as Craft does.
     */
    private function siteIdOrPrimary(): int
    {
        return $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * The entry type's holdable fields (`HoldableField`), with the same layout element UIDs, not required,
     * without conditions, half width, in one tab. Built, not stored: it follows the entry type's layout.
     */
    public function getFieldLayout(): ?FieldLayout
    {
        if ($this->_fieldLayoutTypeId !== $this->typeId) {
            $this->_fieldLayout = $this->buildFieldLayout();
            $this->_fieldLayoutTypeId = $this->typeId;
        }

        return $this->_fieldLayout;
    }

    /**
     * The fields of the preset's layout, chosen or not, in layout order.
     *
     * @return CustomField[]
     */
    public function getLayoutFieldElements(): array
    {
        return $this->getFieldLayout()?->getCustomFieldElements() ?? [];
    }

    /**
     * The chosen fields that are still in the entry type's layout, in layout order.
     *
     * @return CustomField[]
     */
    public function getChosenFieldElements(): array
    {
        return array_values(array_filter(
            $this->getLayoutFieldElements(),
            fn(CustomField $element) => in_array($element->uid, $this->_fieldUids, true),
        ));
    }

    private function buildFieldLayout(): ?FieldLayout
    {
        $source = $this->getType()?->getFieldLayout();
        if (!$source) {
            return null;
        }

        $elements = [];
        foreach ($source->getCustomFieldElements() as $element) {
            if (!HoldableField::of($element)) {
                continue;
            }

            $elements[] = array_merge(['type' => CustomField::class], $element->toArray(), [
                'required' => false,
                'width' => 50,
                'userCondition' => null,
                'elementCondition' => null,
                'editCondition' => null,
                'elementEditCondition' => null,
            ]);
        }

        return FieldLayout::createFromConfig([
            'type' => self::class,
            'tabs' => [[
                'name' => Craft::t('primer', 'Preset'),
                'elements' => $elements,
            ]],
        ]);
    }

    protected function defineRules(): array
    {
        $on = [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE];

        return [
            ...parent::defineRules(),
            [['sectionId', 'typeId'], 'required', 'on' => $on],
            [['sectionId', 'typeId'], 'number', 'integerOnly' => true, 'on' => $on],
            ['typeId', 'validateEntryType', 'on' => $on],
            ['siteId', 'validateSite', 'on' => $on],
            ['typeId', 'validateUnique', 'on' => $on],
            ['fieldUids', 'validateRelationCounts', 'skipOnEmpty' => false, 'on' => $on],
        ];
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

    /**
     * The entry type is one of the section's.
     */
    public function validateEntryType(string $attribute): void
    {
        if (!$this->typeIsInSection()) {
            $this->addError($attribute, Craft::t('primer', 'This entry type isn’t part of the section.'));
        }
    }

    /**
     * The section is enabled for the preset's site.
     */
    public function validateSite(string $attribute): void
    {
        $section = $this->getSection();

        if ($section && !isset($section->getSiteSettings()[$this->siteIdOrPrimary()])) {
            $this->addError($attribute, Craft::t('primer', 'The section isn’t enabled for this site.'));
        }
    }

    /**
     * One preset per section, entry type and site, in every site the preset would span.
     */
    public function validateUnique(string $attribute): void
    {
        $query = self::find()
            ->sectionId($this->sectionId)
            ->typeId($this->typeId)
            ->siteId(array_column($this->getSupportedSites(), 'siteId'));

        if ($this->id) {
            $query->id(['not', $this->id]);
        }

        if ($query->exists()) {
            $this->addError($attribute, Craft::t('primer', 'This entry type already has a preset in this section.'));
        }
    }

    /**
     * The minimum and maximum number of relations of each chosen field.
     *
     * Craft checks them only in the live scenario, and for every field of the layout; a preset checks the chosen ones,
     * and the relation fields of a chosen Content Block, whose form then shows the error at the field.
     */
    public function validateRelationCounts(): void
    {
        foreach ($this->getChosenFieldElements() as $element) {
            HoldableField::of($element)?->validateIn($this);
        }
    }

    public function canView(User $user): bool
    {
        return $user->can(Plugin::ACCESS_PERMISSION);
    }

    /**
     * Saving needs the user to edit the preset's site, and only that one. Craft then propagates it through the scope,
     * and what spans every scope (the field choice, emptying a field taken out of it) reaches every site, also sites
     * the user can't edit, as with Craft's entries.
     */
    public function canSave(User $user): bool
    {
        return $user->can(Plugin::ACCESS_PERMISSION) && $this->canEditSite($user);
    }

    /**
     * Deleting needs the user to edit the preset's site, and only that one: the presets of the other scopes go too.
     */
    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::ACCESS_PERMISSION) && $this->canEditSite($user);
    }

    private function canEditSite(User $user): bool
    {
        $site = Craft::$app->getSites()->getSiteById($this->siteIdOrPrimary(), true);

        return $site !== null && $user->can("editSite:$site->uid");
    }

    /**
     * The UIDs of the fields of the preset's layout, in layout order.
     *
     * @return string[]
     */
    public function getLayoutFieldUids(): array
    {
        return array_map(fn(CustomField $element) => (string)$element->uid, $this->getLayoutFieldElements());
    }

    public function beforeSave(bool $isNew): bool
    {
        if ($this->getFieldLayout()) {
            // A chosen field that left the entry type's layout is dropped from the choice.
            $this->_fieldUids = FieldChoice::kept($this->_fieldUids, $this->getLayoutFieldUids());

            // A field that isn't chosen holds no value, whatever the form posted for it.
            $unchosen = FieldChoice::unchosen($this->getLayoutFieldUids(), $this->_fieldUids);
            foreach ($this->getLayoutFieldElements() as $element) {
                $field = HoldableField::of($element);
                if ($field && in_array($element->uid, $unchosen, true)) {
                    $this->setFieldValue($field->handle(), $field->emptyValue());
                }
            }
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $this->_choiceBefore = $this->sharedChoiceBefore($isNew);

            $columns = [
                'sectionId' => $this->sectionId,
                'typeId' => $this->typeId,
                'fieldUids' => $this->_fieldUids,
            ];

            if ($isNew) {
                Db::insert(self::TABLE, ['id' => $this->id] + $columns);
            } else {
                Db::update(self::TABLE, $columns, ['id' => $this->id]);
            }

            // The field choice is the same in every scope: the other scopes' presets of the entry type follow.
            Db::update(self::TABLE, ['fieldUids' => $this->_fieldUids], [
                'and',
                ['sectionId' => $this->sectionId, 'typeId' => $this->typeId],
                ['not', ['id' => $this->id]],
            ]);
        }

        parent::afterSave($isNew);
    }

    /**
     * Once the preset is saved in every site of its scope: a field the save took out of the choice is emptied in
     * every other site of every scope too, so a field chosen again starts empty.
     */
    public function afterPropagate(bool $isNew): void
    {
        parent::afterPropagate($isNew);

        $before = $this->_choiceBefore;
        $this->_choiceBefore = null;

        if ($before !== null && FieldChoice::removed($before, $this->_fieldUids, $this->getLayoutFieldUids())) {
            $this->emptyUnchosenFieldsElsewhere();
        }
    }

    /**
     * The presets of the entry type in every other scope go too, also in sites the user can't edit.
     */
    public function afterDelete(): void
    {
        parent::afterDelete();

        if (!$this->sectionId || !$this->typeId) {
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
            Craft::$app->getElements()->deleteElement($other, $this->hardDelete);
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
        $query = (new Query())
            ->select('fieldUids')
            ->from(self::TABLE)
            ->where(['sectionId' => $this->sectionId, 'typeId' => $this->typeId])
            ->orderBy(['id' => SORT_ASC]);

        if (!$isNew) {
            $query->andWhere(['id' => $this->id]);
        }

        $fieldUids = $query->scalar();

        return is_string($fieldUids) ? array_values(array_filter((array)Json::decodeIfJson($fieldUids), 'is_string')) : [];
    }

    /**
     * Saves the entry type's presets in every site but this one's: each empties the fields it doesn't choose, in its
     * own site only. Fields of one site don't propagate, so each site is saved.
     */
    private function emptyUnchosenFieldsElsewhere(): void
    {
        $elements = Craft::$app->getElements();

        foreach (self::find()->sectionId($this->sectionId)->typeId($this->typeId)->site('*')->all() as $preset) {
            if ($preset->id !== $this->id || $preset->siteId !== $this->siteId) {
                $elements->saveElement($preset, false, false, false);
            }
        }
    }
}
