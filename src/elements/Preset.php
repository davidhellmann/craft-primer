<?php

namespace davidhellmann\primer\elements;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\ContentBlock as ContentBlockElement;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets;
use craft\fields\BaseRelationField;
use craft\fields\ContentBlock;
use craft\fields\Entries;
use craft\fields\Users;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use davidhellmann\primer\decisions\FieldChoice;
use davidhellmann\primer\decisions\Scope;
use davidhellmann\primer\elements\db\PresetQuery;

/**
 * The starting elements for the chosen relation fields of one entry type in one section.
 *
 * Its field layout is built from the entry type's, with the same layout element UIDs, so Craft stores,
 * relates and propagates a preset's values exactly as it does an entry's, and they map one to one.
 *
 * @property string[] $fieldUids
 */
class Preset extends Element
{
    public const TABLE = '{{%primer_presets}}';

    /**
     * The relation fields: the fields a preset can hold.
     */
    public const RELATION_FIELD_TYPES = [Entries::class, Assets::class, Users::class];

    public ?int $sectionId = null;
    public ?int $typeId = null;

    /**
     * @var string[] The chosen layout element UIDs.
     */
    private array $_fieldUids = [];

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
     * @return PresetQuery
     */
    public static function find(): PresetQuery
    {
        return new PresetQuery(static::class);
    }

    public static function isRelationField(FieldInterface $field): bool
    {
        foreach (self::RELATION_FIELD_TYPES as $class) {
            if ($field instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The relation fields of a Content Block field's own layout, first level only; none for any other field.
     *
     * @return CustomField[]
     */
    public static function innerRelationElements(FieldInterface $field): array
    {
        if (!$field instanceof ContentBlock) {
            return [];
        }

        return array_values(array_filter(
            $field->getFieldLayout()->getCustomFieldElements(),
            fn(CustomField $element) => self::isRelationField($element->getField()),
        ));
    }

    /**
     * Whether a preset can hold the field: a relation field, or a Content Block with relation fields.
     */
    public static function canHold(FieldInterface $field): bool
    {
        return self::isRelationField($field) || self::innerRelationElements($field);
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
     * The entry type's fields a preset can hold (`canHold()`), with the same layout element UIDs, not required,
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
            if (!self::canHold($element->getField())) {
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
            $field = $element->getField();
            if ($field instanceof BaseRelationField) {
                $field->validateRelationCount($this);
                continue;
            }

            $block = $this->getFieldValue($element->attribute());
            if (!$block instanceof ContentBlockElement) {
                continue;
            }
            foreach (self::innerRelationElements($field) as $inner) {
                $innerField = $inner->getField();
                if ($innerField instanceof BaseRelationField) {
                    $innerField->validateRelationCount($block);
                }
            }
            if ($block->hasErrors()) {
                $this->addModelErrors($block, $element->attribute());
            }
        }
    }

    public function canView(User $user): bool
    {
        return $user->can('accessPlugin-primer');
    }

    /**
     * Saving needs the user to edit the preset's site; Craft then propagates it through the scope, as for entries.
     */
    public function canSave(User $user): bool
    {
        return $user->can('accessPlugin-primer') && $this->canEditSite($user);
    }

    public function canDelete(User $user): bool
    {
        return $user->can('accessPlugin-primer') && $this->canEditSite($user);
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

    /**
     * The value that empties a field of the preset: no relations, or none in any of a Content Block's relation fields.
     * The block's array form sets just those, so whatever else the block holds stays.
     */
    public static function emptyValue(CustomField $element): mixed
    {
        $inner = self::innerRelationElements($element->getField());
        if (!$inner) {
            return [];
        }

        return ['fields' => array_fill_keys(array_map(fn(CustomField $e) => $e->attribute(), $inner), [])];
    }

    public function beforeSave(bool $isNew): bool
    {
        if ($this->getFieldLayout()) {
            // A chosen field that left the entry type's layout is dropped from the choice.
            $this->_fieldUids = FieldChoice::kept($this->_fieldUids, $this->getLayoutFieldUids());

            // A field that isn't chosen holds no value, whatever the form posted for it.
            $unchosen = FieldChoice::unchosen($this->getLayoutFieldUids(), $this->_fieldUids);
            foreach ($this->getLayoutFieldElements() as $element) {
                if (in_array($element->uid, $unchosen, true)) {
                    $this->setFieldValue($element->attribute(), self::emptyValue($element));
                }
            }
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
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
}
