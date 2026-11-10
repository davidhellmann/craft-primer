<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\User\Models\User;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Support\Facades\DB;

use function CraftCms\Cms\cp_url;

/**
 * The field choice is the same in every site and every scope: a field taken out of it is emptied in all of them,
 * whatever its translation method, and starts empty when it is chosen again.
 *
 * Section `pagesSiteGroup` has two scopes: Corporate EN and DE, Shop EN and FR.
 */
class FieldChoiceTest extends TestCase
{
    use Content;

    private const SITES = ['corporate' => ['corporateEn', 'corporateDe'], 'shop' => ['shopEn', 'shopFr']];

    /**
     * The relation fields taken out of the choice, one per translation method, and the one that stays.
     */
    private const REMOVED = ['entriesNone', 'entriesSite', 'entriesSiteGroup', 'entriesLanguage'];
    private const KEPT = 'usersNone';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pro();
    }

    public function testRemovingAFieldEmptiesItInEverySiteOfEveryScope(): void
    {
        $presets = $this->presetsWithEveryFieldFilled();
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        // Saved in Corporate DE, not the site the preset was created in.
        $this->save($presets['corporate'], 'corporateDe', [self::KEPT]);

        $this->assertOnlyTheKeptFieldHoldsValues($presets);
    }

    public function testAPresetSavedDirectlyEmptiesTheRemovedFieldsEverywhereToo(): void
    {
        $presets = $this->presetsWithEveryFieldFilled();

        // As any code saving the element, not the editor.
        $preset = Preset::find()->id($presets['corporate'])->site('corporateEn')->one();
        $preset->setFieldUids([$this->layoutElementUid('page', self::KEPT)]);
        $this->assertTrue(Elements::saveElement($preset));

        $this->assertOnlyTheKeptFieldHoldsValues($presets);
    }

    public function testAUserWhoMayEditOneSiteChangesTheChoiceAndDeletesInEveryScope(): void
    {
        $presets = $this->presetsWithEveryFieldFilled();
        $this->actingAs(User::query()->findOrFail($this->editor(['corporateEn'])->id));

        // The permission is checked for the site saved in only; the choice reaches every scope, as Craft's entries do.
        $this->save($presets['corporate'], 'corporateEn', [self::KEPT]);
        $this->assertOnlyTheKeptFieldHoldsValues($presets);

        $this->postJson(cp_url('actions/elements/delete'), [
            'elementType' => Preset::class,
            'elementId' => $presets['corporate'],
            'siteId' => $this->site('corporateEn')->id,
        ])->assertOk();

        $this->assertSame([], Preset::find()->id(array_values($presets))->site('*')->ids());
    }

    public function testAFieldChosenAgainStartsEmpty(): void
    {
        $presets = $this->presetsWithEveryFieldFilled();
        $this->actingAs(User::query()->findOrFail($this->admin()->id));
        $this->save($presets['corporate'], 'corporateEn', [self::KEPT]);

        $this->save($presets['shop'], 'shopFr', [self::KEPT, ...self::REMOVED]);

        foreach ($this->everySite($presets) as [$id, $site]) {
            foreach (self::REMOVED as $handle) {
                $this->assertSame([], $this->ids(Preset::find()->id($id)->site($site)->one()->getFieldValue($handle)), "$handle in $site");
            }
        }
    }

    public function testANewPresetInAnotherScopeTakesTheFieldOutOfTheOthers(): void
    {
        $book = $this->book();
        $corporate = $this->preset('pagesSiteGroup', 'page', ['entriesSite' => [$book->id], self::KEPT => []], 'corporateEn');

        $this->preset('pagesSiteGroup', 'page', [self::KEPT => []], 'shopEn');

        foreach (self::SITES['corporate'] as $site) {
            $this->assertFalse($this->holds($corporate->id, $site, 'entriesSite'), $site);
        }
    }

    public function testANewPresetStartsWithTheChoiceItsEntryTypesOtherScopesShare(): void
    {
        $this->preset('pagesSiteGroup', 'page', ['entriesSite' => [], self::KEPT => []], 'corporateEn');
        $preset = new Preset(['siteId' => $this->site('shopEn')->id]);

        $preset->setAttributesFromRequest([
            'sectionId' => $this->section('pagesSiteGroup')->id,
            'typeId' => $this->entryType('page')->id,
        ]);

        $this->assertSame(
            [$this->layoutElementUid('page', 'entriesSite'), $this->layoutElementUid('page', self::KEPT)],
            $preset->getFieldUids(),
        );
    }

    public function testSavingWithTheSameChoiceKeepsTheOtherSitesValues(): void
    {
        $presets = $this->presetsWithEveryFieldFilled();
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        $this->save($presets['corporate'], 'corporateEn', [self::KEPT, ...self::REMOVED]);

        foreach (self::SITES['shop'] as $site) {
            foreach (self::REMOVED as $handle) {
                $this->assertTrue($this->holds($presets['shop'], $site, $handle), "$handle in $site");
            }
        }
    }

    /**
     * @param array<string, int> $presets
     */
    private function assertOnlyTheKeptFieldHoldsValues(array $presets): void
    {
        foreach ($this->everySite($presets) as [$id, $site]) {
            foreach (self::REMOVED as $handle) {
                $this->assertFalse($this->holds($id, $site, $handle), "$handle in $site");
            }
            $this->assertTrue($this->holds($id, $site, self::KEPT), self::KEPT . " in $site");
            $this->assertSame([$this->layoutElementUid('page', self::KEPT)], Preset::find()->id($id)->site($site)->one()->getFieldUids(), "Choice in $site");
        }
    }

    /**
     * One preset per scope, every field chosen and filled in every site.
     *
     * @return array{corporate: int, shop: int} Preset IDs by scope.
     */
    private function presetsWithEveryFieldFilled(): array
    {
        $book = $this->book();
        $values = array_fill_keys(self::REMOVED, [$book->id]) + [self::KEPT => [$this->admin()->id]];

        $presets = [
            'corporate' => $this->preset('pagesSiteGroup', 'page', $values, 'corporateEn')->id,
            'shop' => $this->preset('pagesSiteGroup', 'page', $values, 'shopEn')->id,
        ];

        // The first save copied every value through the scope.
        foreach ($this->everySite($presets) as [$id, $site]) {
            foreach ([...self::REMOVED, self::KEPT] as $handle) {
                $this->assertTrue($this->holds($id, $site, $handle), "$handle in $site before");
            }
        }

        return $presets;
    }

    /**
     * Saves a preset as Craft's element editor does.
     *
     * @param string[] $handles The chosen fields.
     */
    private function save(int $presetId, string $site, array $handles): void
    {
        $this->postJson(cp_url('actions/elements/save'), [
            'elementType' => Preset::class,
            'elementId' => $presetId,
            'siteId' => $this->site($site)->id,
            'fieldUids' => array_map(fn(string $handle) => $this->layoutElementUid('page', $handle), $handles),
        ])->assertOk();
    }

    /**
     * Whether a preset holds anything in a field in a site: in its content, or in Craft's relations.
     */
    private function holds(int $presetId, string $site, string $handle): bool
    {
        $siteId = $this->site($site)->id;
        $field = Fields::getFieldByHandle($handle);
        $content = DB::table(Table::ELEMENTS_SITES)->where('elementId', $presetId)->where('siteId', $siteId)->value('content');
        $content = is_string($content) ? Json::decode($content) : (array)$content;

        $relations = DB::table(Table::RELATIONS)
            ->where('sourceId', $presetId)
            ->where('fieldId', $field->id)
            ->where(fn($query) => $query->whereNull('sourceSiteId')->orWhere('sourceSiteId', $siteId))
            ->exists();

        return $relations || !empty($content[$this->layoutElementUid('page', $handle)]);
    }

    /**
     * @param array<string, int> $presets
     * @return array<array{int, string}> Each preset with each site of its scope.
     */
    private function everySite(array $presets): array
    {
        $pairs = [];
        foreach ($presets as $scope => $id) {
            foreach (self::SITES[$scope] as $site) {
                $pairs[] = [$id, $site];
            }
        }

        return $pairs;
    }
}
