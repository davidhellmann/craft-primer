<?php

namespace davidhellmann\primer\elements;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets;
use craft\fields\BaseRelationField;
use craft\fields\Entries;
use craft\fields\Users;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
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
    private array $fieldUids = [];

    private ?FieldLayout $fieldLayout = null;
    private ?int $fieldLayoutTypeId = null;

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
     * @return string[]
     */
    public function getFieldUids(): array
    {
        return $this->fieldUids;
    }

    /**
     * @param string[]|string|null $uids The chosen layout element UIDs, or their JSON as stored.
     */
    public function setFieldUids(array|string|null $uids): void
    {
        if (is_string($uids)) {
            $uids = Json::decodeIfJson($uids);
        }

        $this->fieldUids = array_values(array_unique(array_filter((array)$uids, 'is_string')));
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
     * The sites the section is enabled in. Ticket 14 narrows this to the preset's scope.
     */
    public function getSupportedSites(): array
    {
        $section = $this->getSection();
        if (!$section) {
            // Without a section the save fails validation; Craft needs a site to get there.
            return [$this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id];
        }

        return array_keys($section->getSiteSettings());
    }

    /**
     * The entry type's relation fields, with the same layout element UIDs, not required, without conditions,
     * half width, in one tab. Built, not stored: it follows the entry type's layout.
     */
    public function getFieldLayout(): ?FieldLayout
    {
        if ($this->fieldLayoutTypeId !== $this->typeId) {
            $this->fieldLayout = $this->buildFieldLayout();
            $this->fieldLayoutTypeId = $this->typeId;
        }

        return $this->fieldLayout;
    }

    /**
     * The relation fields of the preset's layout, chosen or not, in layout order.
     *
     * @return CustomField[]
     */
    public function getRelationFieldElements(): array
    {
        return $this->getFieldLayout()?->getCustomFieldElements() ?? [];
    }

    /**
     * The chosen relation fields that are still in the entry type's layout, in layout order.
     *
     * @return CustomField[]
     */
    public function getChosenFieldElements(): array
    {
        return array_values(array_filter(
            $this->getRelationFieldElements(),
            fn(CustomField $element) => in_array($element->uid, $this->fieldUids, true),
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
            if (!self::isRelationField($element->getField())) {
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
            ['typeId', 'validateUnique', 'on' => $on],
            ['fieldUids', 'validateRelationCounts', 'skipOnEmpty' => false, 'on' => $on],
        ];
    }

    /**
     * The entry type is one of the section's.
     */
    public function validateEntryType(string $attribute): void
    {
        $typeIds = array_map(fn(EntryType $type) => $type->id, $this->getSection()?->getEntryTypes() ?? []);

        if (!in_array($this->typeId, $typeIds, true)) {
            $this->addError($attribute, Craft::t('primer', 'This entry type isn’t part of the section.'));
        }
    }

    /**
     * One preset per section, entry type and site.
     */
    public function validateUnique(string $attribute): void
    {
        $query = self::find()
            ->sectionId($this->sectionId)
            ->typeId($this->typeId)
            ->siteId($this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);

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
     * Craft checks them only in the live scenario, and for every field of the layout; a preset checks the chosen ones.
     */
    public function validateRelationCounts(): void
    {
        foreach ($this->getChosenFieldElements() as $element) {
            $field = $element->getField();
            if ($field instanceof BaseRelationField) {
                $field->validateRelationCount($this);
            }
        }
    }

    public function canView(User $user): bool
    {
        return $user->can('accessPlugin-primer');
    }

    public function canSave(User $user): bool
    {
        return $user->can('accessPlugin-primer');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('accessPlugin-primer');
    }

    public function beforeSave(bool $isNew): bool
    {
        // A field that isn't chosen holds no value, whatever the form posted for it.
        foreach ($this->getRelationFieldElements() as $element) {
            if (!in_array($element->uid, $this->fieldUids, true)) {
                $this->setFieldValue($element->attribute(), []);
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
                'fieldUids' => $this->fieldUids,
            ];

            if ($isNew) {
                Db::insert(self::TABLE, ['id' => $this->id] + $columns);
            } else {
                Db::update(self::TABLE, $columns, ['id' => $this->id]);
            }
        }

        parent::afterSave($isNew);
    }
}
