<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\ContentBlock as ContentBlockField;
use CraftCms\Cms\Field\Elements\ContentBlock;
use CraftCms\Cms\Field\Entries as EntriesField;
use CraftCms\Cms\FieldLayout\FieldLayoutElement;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\User\Models\User;
use DateTime;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function CraftCms\Cms\cp_url;

/**
 * A Content Block field whose layout holds relation fields is chosen in a preset as a whole. Section `features` (All
 * propagation, every site) has the entry types `page` and `feature`; `feature` holds `entriesSite` and the block
 * `teaser`: teaserEntries (translation none, at most 2), teaserAssets (site), teaserUsers (language) and the Plain
 * Text teaserText (none).
 */
class ContentBlockTest extends TestCase
{
    use Content;

    private const INNER = ['teaserEntries', 'teaserAssets', 'teaserUsers'];
    private const SITES = ['corporateEn', 'corporateDe', 'shopEn', 'shopFr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pro();
    }

    public function testTheFieldChoiceOffersTheBlockAsOneField(): void
    {
        $preset = new Preset(['sectionId' => $this->section('features')->id, 'typeId' => $this->entryType('feature')->id]);

        $this->assertSame(['entriesSite', 'teaser'], array_map(fn(CustomField $e) => $e->attribute(), $preset->getLayoutFieldElements()));
    }

    public function testInsideAPresetTheBlockShowsOnlyItsRelationFields(): void
    {
        $preset = $this->preset('features', 'feature', ['teaser' => []]);
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        $ui = Json::encode(AssertableInertia::fromTestResponse($this->get(cp_url("primer/presets/$preset->id"))->assertOk())
            ->toArray()['props']['ui']);

        foreach (self::INNER as $handle) {
            $this->assertStringContainsString("\"$handle\"", $ui);
        }
        $this->assertStringNotContainsString('"teaserText"', $ui);
    }

    public function testAnEntryShowsTheWholeBlockAsBefore(): void
    {
        $this->preset('features', 'feature', ['teaser' => []]);
        $entry = $this->newFeature('corporateEn');
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        $ui = Json::encode(AssertableInertia::fromTestResponse($this->get($entry->getCpEditUrl())->assertOk())
            ->toArray()['props']['ui']);

        foreach ([...self::INNER, 'teaserText'] as $handle) {
            $this->assertStringContainsString("\"$handle\"", $ui);
        }
    }

    public function testAPresetsBlockFollowsCraftAcrossSites(): void
    {
        [$first, $second] = $this->values('first', 'second');
        $preset = $this->preset('features', 'feature', ['teaser' => ['fields' => $first]], 'corporateEn');

        // The first save copies the block to every site of the scope.
        foreach (self::SITES as $site) {
            $this->assertSame($first, $this->presetBlock($preset->id, $site), $site);
        }

        // Afterwards each site has its own block; the inner fields still share by their translation method.
        $this->save($preset->id, 'shopEn', ['teaser'], ['teaser' => ['fields' => $second]]);

        $this->assertSame([
            'corporateEn' => ['teaserEntries' => $second['teaserEntries'], 'teaserAssets' => $first['teaserAssets'], 'teaserUsers' => $second['teaserUsers']],
            'corporateDe' => ['teaserEntries' => $second['teaserEntries'], 'teaserAssets' => $first['teaserAssets'], 'teaserUsers' => $first['teaserUsers']],
            'shopEn' => $second,
            'shopFr' => ['teaserEntries' => $second['teaserEntries'], 'teaserAssets' => $first['teaserAssets'], 'teaserUsers' => $first['teaserUsers']],
        ], array_combine(self::SITES, array_map(fn(string $site) => $this->presetBlock($preset->id, $site), self::SITES)));
    }

    public function testAnEntryGetsTheBlockOfThePresetInItsSite(): void
    {
        [$first, $second] = $this->values('first', 'second');
        $preset = $this->preset('features', 'feature', ['teaser' => ['fields' => $first]], 'corporateEn');
        $this->save($preset->id, 'shopFr', ['teaser'], ['teaser' => ['fields' => $second]]);
        $this->assertSame($second, $this->presetBlock($preset->id, 'shopFr'));

        $onA = $this->newFeature('corporateEn');
        $onB = $this->newFeature('shopFr');

        // Craft copies the block of the site it was created in to every site of the section.
        $onAInEn = ['teaserEntries' => $second['teaserEntries'], 'teaserAssets' => $first['teaserAssets'], 'teaserUsers' => $first['teaserUsers']];
        foreach (self::SITES as $site) {
            $this->assertSame($onAInEn, $this->entryBlock($onA->id, $site), "Created on Corporate EN, in $site");
            $this->assertSame($second, $this->entryBlock($onB->id, $site), "Created on Shop FR, in $site");
        }
    }

    public function testABlockThatAlreadyHoldsValuesKeepsThem(): void
    {
        [$preset, $import] = $this->values('preset', 'import');
        $this->preset('features', 'feature', ['teaser' => ['fields' => $preset]]);

        // As an import or the editor sets it: one relation field and the text already in the block.
        $entry = $this->newFeature('corporateEn', ['teaser' => ['fields' => [
            'teaserAssets' => $import['teaserAssets'],
            'teaserText' => 'Imported',
        ]]]);

        $this->assertSame(
            ['teaserEntries' => $preset['teaserEntries'], 'teaserAssets' => $import['teaserAssets'], 'teaserUsers' => $preset['teaserUsers']],
            $this->entryBlock($entry->id, 'corporateEn'),
        );
        $saved = Entry::find()->id($entry->id)->site('corporateEn')->one();
        $this->assertSame('Imported', $saved->getFieldValue('teaser')->getFieldValue('teaserText'));
    }

    public function testABlockFieldTakesNoMoreThanItsMaximum(): void
    {
        $books = [$this->book('One')->id, $this->book('Two')->id];
        $this->preset('features', 'feature', ['teaser' => ['fields' => ['teaserEntries' => $books]]]);
        // The field allows fewer elements now than the preset holds.
        $field = Fields::getFieldByHandle('teaserEntries');
        $this->assertInstanceOf(EntriesField::class, $field);
        $field->maxRelations = 1;
        $this->assertTrue(Fields::saveField($field));

        $entry = $this->newFeature('corporateEn');

        $this->assertSame([$books[0]], $this->entryBlock($entry->id, 'corporateEn')['teaserEntries']);
    }

    public function testAPresetsBlockFieldHoldsNoMoreThanItsMaximum(): void
    {
        $preset = $this->preset('features', 'feature', ['teaser' => []]);
        $books = array_map(fn(string $title) => $this->book($title)->id, ['One', 'Two', 'Three']);

        $response = $this->postPreset($preset->id, 'corporateEn', ['teaser'], ['teaser' => ['fields' => ['teaserEntries' => $books]]]);

        $response->assertStatus(400);
        $this->assertContains('*Teaser entries (none)* should contain at most 2 selections.', $response->json('errors')['teaser.teaserEntries'] ?? $response->json('errors')['teaser'] ?? []);
        $this->assertSame([], $this->presetBlock($preset->id, 'corporateEn')['teaserEntries']);
    }

    public function testANewPresetWithTheBlockSavesThoughAnInnerFieldNeedsAMinimum(): void
    {
        $field = Fields::getFieldByHandle('teaserEntries');
        $this->assertInstanceOf(EntriesField::class, $field);
        $field->minRelations = 1;
        $this->assertTrue(Fields::saveField($field));

        // Never saved, the preset can't fill the block yet.
        $preset = $this->preset('features', 'feature', ['teaser' => []]);

        // Once saved, it can, and the minimum holds.
        $preset = $this->stored($preset);
        $this->assertFalse(Elements::saveElement($preset));
        $this->assertTrue($preset->errors()->has('teaser.teaserEntries'));
    }

    public function testSwitchingToATypeWithTheBlockFillsIt(): void
    {
        [$values] = $this->values('preset');
        $this->preset('features', 'feature', ['teaser' => ['fields' => $values]]);
        $entry = $this->entry('features', 'page', ['title' => 'Launch']);

        $entry->setTypeId($this->entryType('feature')->id);
        $this->assertTrue(Elements::saveElement($entry));

        $this->assertSame($values, $this->entryBlock($entry->id, 'corporateEn'));
    }

    public function testRemovingTheBlockEmptiesItsRelationFieldsInEverySite(): void
    {
        [$values] = $this->values('preset');
        $book = $this->book('Kept');
        $preset = $this->preset('features', 'feature', ['teaser' => ['fields' => $values], 'entriesSite' => [$book->id]], 'corporateEn');

        $this->save($preset->id, 'corporateDe', ['entriesSite']);

        $this->assertSame([], ContentBlock::find()->primaryOwnerId($preset->id)->site('*')->status(null)->ids(), 'The blocks went');
        foreach (self::SITES as $site) {
            $read = Preset::find()->id($preset->id)->site($site)->one();
            $this->assertSame([$book->id], $this->ids($read->getFieldValue('entriesSite')), "entriesSite in $site");
        }

        // Chosen again, it starts empty and fills nothing.
        $this->save($preset->id, 'shopFr', ['entriesSite', 'teaser']);
        $empty = array_fill_keys(self::INNER, []);
        foreach (self::SITES as $site) {
            $this->assertSame($empty, $this->presetBlock($preset->id, $site), $site);
        }
        $this->assertSame($empty, $this->entryBlock($this->newFeature('corporateEn')->id, 'corporateEn'));
    }

    public function testABlockAddedBackToTheLayoutDoesntBringTheOldValuesBack(): void
    {
        [$values] = $this->values('preset');
        $preset = $this->preset('features', 'feature', ['teaser' => ['fields' => $values]], 'corporateEn');
        $oldUid = $this->layoutElementUid('feature', 'teaser');

        $this->removeTeaserFromLayout();
        $this->addTeaserToLayout();
        $this->assertNotSame($oldUid, $this->layoutElementUid('feature', 'teaser'));

        $this->assertSame([], ContentBlock::find()->primaryOwnerId($preset->id)->site('*')->status(null)->ids(), 'The blocks went');

        // Chosen again, it fills nothing.
        $this->save($preset->id, 'corporateEn', ['teaser']);
        $empty = array_fill_keys(self::INNER, []);
        $this->assertSame($empty, $this->presetBlock($preset->id, 'corporateEn'));
        $this->assertSame($empty, $this->entryBlock($this->newFeature('corporateEn')->id, 'corporateEn'));
    }

    public function testABlockReplacedInOneSaveDoesntBringTheOldValuesBack(): void
    {
        [$values] = $this->values('preset');
        $preset = $this->preset('features', 'feature', ['teaser' => ['fields' => $values]], 'corporateEn');
        $oldUid = $this->layoutElementUid('feature', 'teaser');

        $type = $this->entryType('feature');
        foreach ($type->getFieldLayout()->getTabs() as $tab) {
            $tab->setElements(array_map(
                fn($element) => $element instanceof CustomField && $element->getField()->handle === 'teaser'
                    ? new CustomField($element->getField(), ['dateAdded' => new DateTime()])
                    : $element,
                $tab->getElements(),
            ));
        }
        $this->assertTrue(EntryTypes::saveEntryType($type));
        $this->assertNotSame($oldUid, $this->layoutElementUid('feature', 'teaser'));

        $this->assertSame([], ContentBlock::find()->primaryOwnerId($preset->id)->site('*')->status(null)->ids(), 'The blocks went');
    }

    public function testAnInnerFieldAddedBackToTheBlocksLayoutDoesntBringTheOldValueBack(): void
    {
        [$values] = $this->values('preset');
        $preset = $this->preset('features', 'feature', ['teaser' => ['fields' => $values]], 'corporateEn');

        // A field save, not an entry type save: the block's own layout loses teaserEntries and gets it back.
        $this->setTeaserElements(fn(array $elements) => array_values(array_filter(
            $elements,
            fn($element) => !$element instanceof CustomField || $element->getField()->handle !== 'teaserEntries',
        )));
        $this->setTeaserElements(fn(array $elements) => [
            ...$elements,
            new CustomField(Fields::getFieldByHandle('teaserEntries'), ['dateAdded' => new DateTime()]),
        ]);

        foreach (self::SITES as $site) {
            $this->assertSame([], $this->presetBlock($preset->id, $site)['teaserEntries'], $site);
        }
        $this->assertSame($values['teaserUsers'], $this->presetBlock($preset->id, 'corporateEn')['teaserUsers'], 'The other inner fields stay');
    }

    /**
     * Saves the Teaser block field with its own layout's elements changed.
     *
     * @param callable(FieldLayoutElement[]): FieldLayoutElement[] $change
     */
    private function setTeaserElements(callable $change): void
    {
        $teaser = Fields::getFieldByHandle('teaser');
        $this->assertInstanceOf(ContentBlockField::class, $teaser);
        $tab = $teaser->getFieldLayout()->getTabs()[0];
        $tab->setElements($change($tab->getElements()));
        $this->assertTrue(Fields::saveField($teaser));

        // As the next request would: the entry type's layout holds the field it read before the save.
        Fields::invalidateCaches();
        EntryTypes::refreshEntryTypes();
    }

    private function removeTeaserFromLayout(): void
    {
        $type = $this->entryType('feature');
        foreach ($type->getFieldLayout()->getTabs() as $tab) {
            $tab->setElements(array_values(array_filter(
                $tab->getElements(),
                fn($element) => !$element instanceof CustomField || $element->getField()->handle !== 'teaser',
            )));
        }
        $this->assertTrue(EntryTypes::saveEntryType($type));
        $this->assertNull($this->entryType('feature')->getFieldLayout()->getFieldByHandle('teaser'), 'teaser left feature');
    }

    /**
     * Adds the block back as an admin does: a new layout element, with a new UID.
     */
    private function addTeaserToLayout(): void
    {
        $type = $this->entryType('feature');
        $tab = $type->getFieldLayout()->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField(Fields::getFieldByHandle('teaser'))]);
        $this->assertTrue(EntryTypes::saveEntryType($type));
    }

    /**
     * Saves a preset as Craft's element editor does.
     *
     * @param string[] $handles The chosen fields.
     * @param array<string, mixed> $fields Posted field values.
     */
    private function save(int $presetId, string $site, array $handles, array $fields = []): void
    {
        $this->postPreset($presetId, $site, $handles, $fields)->assertOk();
    }

    /**
     * @param string[] $handles
     * @param array<string, mixed> $fields
     */
    private function postPreset(int $presetId, string $site, array $handles, array $fields = []): TestResponse
    {
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        return $this->postJson(cp_url('actions/elements/save'), [
            'elementType' => Preset::class,
            'elementId' => $presetId,
            'siteId' => $this->site($site)->id,
            'fieldUids' => array_map(fn(string $handle) => $this->layoutElementUid('feature', $handle), $handles),
            'fields' => $fields,
        ]);
    }

    /**
     * Values for the block's relation fields, one set per name: a book, an image and a user each.
     *
     * @return array<array{teaserEntries: int[], teaserAssets: int[], teaserUsers: int[]}>
     */
    private function values(string ...$names): array
    {
        return array_map(fn(string $name) => [
            'teaserEntries' => [$this->book("Book $name")->id],
            'teaserAssets' => [$this->image("$name.png")->id],
            'teaserUsers' => [$this->user($name)->id],
        ], $names);
    }

    /**
     * The block's relation field values of a preset in a site.
     *
     * @return array<string, int[]>
     */
    private function presetBlock(int $presetId, string $site): array
    {
        $preset = Preset::find()->id($presetId)->site($site)->one();

        return $this->relationIds($preset->getFieldValue('teaser'));
    }

    /**
     * The block's relation field values of an entry in a site.
     *
     * @return array<string, int[]>
     */
    private function entryBlock(int $entryId, string $site): array
    {
        $entry = Entry::find()->id($entryId)->site($site)->status(null)->one();

        return $this->relationIds($entry->getFieldValue('teaser'));
    }

    /**
     * @return array<string, int[]>
     */
    private function relationIds(ContentBlock $block): array
    {
        return array_combine(self::INNER, array_map(
            fn(string $handle) => $this->ids($block->getFieldValue($handle)->limit(null)),
            self::INNER,
        ));
    }

    /**
     * A saved Feature entry, created in a site.
     *
     * @param array<string, mixed> $values Field values by handle.
     */
    private function newFeature(string $site, array $values = []): Entry
    {
        $entry = new Entry([
            'sectionId' => $this->section('features')->id,
            'typeId' => $this->entryType('feature')->id,
            'siteId' => $this->site($site)->id,
            'title' => 'Launch',
        ]);
        $entry->setFieldValues($values);
        $this->assertTrue(Elements::saveElement($entry), implode(', ', $entry->errors()->all()));

        return $entry;
    }
}
