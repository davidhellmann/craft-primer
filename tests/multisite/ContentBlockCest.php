<?php

namespace davidhellmann\primer\tests\multisite;

use Craft;
use craft\base\FieldLayoutElement;
use craft\elements\ContentBlock;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\ContentBlock as ContentBlockField;
use craft\fields\Entries as EntriesField;
use DateTime;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * A Content Block field whose layout holds relation fields is chosen in a preset as a whole. Section `features` (All
 * propagation, every site) has the entry types `page` and `feature`; `feature` holds `entriesSite` and the block
 * `teaser`: teaserEntries (translation none, at most 2), teaserAssets (site), teaserUsers (language) and the Plain
 * Text teaserText (none).
 */
class ContentBlockCest
{
    private const INNER = ['teaserEntries', 'teaserAssets', 'teaserUsers'];
    private const SITES = ['corporateEn', 'corporateDe', 'shopEn', 'shopFr'];

    public function _before(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
    }

    public function addFieldOffersTheBlockAsOneField(IntegrationTester $I): void
    {
        $I->amOnPage($this->newPresetUrl($I));

        $I->seeResponseCodeIs(200);
        $teaser = $I->layoutElementUid('feature', 'teaser');
        $I->seeElement("[data-primer-add=\"$teaser\"]");
        $I->seeElement('[data-primer-add="' . $I->layoutElementUid('feature', 'entriesSite') . '"]');
        $I->assertCount(2, $I->grabMultiple('[data-primer-add]', 'data-primer-add'));
    }

    public function aNeverSavedPresetShowsCraftsOwnMessageForTheBlock(IntegrationTester $I): void
    {
        $I->amOnPage($this->newPresetUrl($I));

        $teaser = $I->layoutElementUid('feature', 'teaser');
        $I->see('Content Blocks can only be created after the preset has been saved.', "[data-primer-field=\"$teaser\"]");
        // Opening the screen saves nothing.
        $I->assertNull(Preset::find()->site('*')->status(null)->one());
        $I->assertNull(ContentBlock::find()->site('*')->status(null)->one());
    }

    public function insideAPresetTheBlockShowsOnlyItsRelationFields(IntegrationTester $I): void
    {
        $preset = $I->preset('features', 'feature', ['teaser' => []]);

        $I->amOnPage("/admin/primer/presets/$preset->id");

        $teaser = $I->layoutElementUid('feature', 'teaser');
        foreach (self::INNER as $handle) {
            $I->seeElement("[data-primer-field=\"$teaser\"] [data-attribute=\"$handle\"]");
        }
        $I->dontSeeElement("[data-primer-field=\"$teaser\"] [data-attribute=\"teaserText\"]");

        // The slideout renders the same.
        $content = $I->slideout('primer/presets/edit', ['presetId' => $preset->id])['content'];
        foreach (self::INNER as $handle) {
            $I->assertStringContainsString("data-attribute=\"$handle\"", $content);
        }
        $I->assertStringNotContainsString('data-attribute="teaserText"', $content);
    }

    public function anEntryShowsTheWholeBlockAsBefore(IntegrationTester $I): void
    {
        $I->preset('features', 'feature', ['teaser' => []]);
        $entry = $I->entry('features', 'feature', ['title' => 'Launch']);

        $I->amOnPage("/admin/entries/features/$entry->id");

        $I->seeResponseCodeIs(200);
        foreach ([...self::INNER, 'teaserText'] as $handle) {
            $I->seeElement("[data-attribute=\"teaser\"] [data-attribute=\"$handle\"]");
        }
        $teaser = Craft::$app->getFields()->getFieldByHandle('teaser');
        $I->assertInstanceOf(ContentBlockField::class, $teaser);
        $I->assertCount(4, $teaser->getFieldLayout()->getCustomFieldElements());
    }

    public function aPresetsBlockFollowsCraftAcrossSites(IntegrationTester $I): void
    {
        [$first, $second] = $this->values($I, 'first', 'second');
        $preset = $I->preset('features', 'feature', ['teaser' => ['fields' => $first]], 'corporateEn');

        // The first save copies the block to every site of the scope.
        foreach (self::SITES as $site) {
            $I->assertSame($first, $this->presetBlock($preset->id, $site), $site);
        }

        // Afterwards each site has its own block; the inner fields still share by their translation method.
        $this->save($I, $preset->id, 'shopEn', ['teaser'], ['teaser' => ['fields' => $second]]);

        $I->assertSame([
            'corporateEn' => ['teaserEntries' => $second['teaserEntries'], 'teaserAssets' => $first['teaserAssets'], 'teaserUsers' => $second['teaserUsers']],
            'corporateDe' => ['teaserEntries' => $second['teaserEntries'], 'teaserAssets' => $first['teaserAssets'], 'teaserUsers' => $first['teaserUsers']],
            'shopEn' => $second,
            'shopFr' => ['teaserEntries' => $second['teaserEntries'], 'teaserAssets' => $first['teaserAssets'], 'teaserUsers' => $first['teaserUsers']],
        ], array_combine(self::SITES, array_map(fn(string $site) => $this->presetBlock($preset->id, $site), self::SITES)));
    }

    public function anEntryGetsTheBlockOfThePresetInItsSite(IntegrationTester $I): void
    {
        [$first, $second] = $this->values($I, 'first', 'second');
        $preset = $I->preset('features', 'feature', ['teaser' => ['fields' => $first]], 'corporateEn');
        $this->save($I, $preset->id, 'shopFr', ['teaser'], ['teaser' => ['fields' => $second]]);
        // In Shop FR: the shared entries and Shop FR's own assets and users.
        $shopFr = $this->presetBlock($preset->id, 'shopFr');
        $I->assertSame($second, $shopFr);

        $onA = $this->newFeature($I, 'corporateEn');
        $onB = $this->newFeature($I, 'shopFr');

        // Craft copies the block of the site it was created in to every site of the section.
        $onAInEn = ['teaserEntries' => $second['teaserEntries'], 'teaserAssets' => $first['teaserAssets'], 'teaserUsers' => $first['teaserUsers']];
        foreach (self::SITES as $site) {
            $I->assertSame($onAInEn, $this->entryBlock($onA->id, $site), "Created on Corporate EN, in $site");
            $I->assertSame($second, $this->entryBlock($onB->id, $site), "Created on Shop FR, in $site");
        }
    }

    public function aBlockThatAlreadyHoldsValuesKeepsThem(IntegrationTester $I): void
    {
        [$preset, $import] = $this->values($I, 'preset', 'import');
        $I->preset('features', 'feature', ['teaser' => ['fields' => $preset]]);

        // As an import or the editor sets it: one relation field and the text already in the block.
        $entry = $this->newFeature($I, 'corporateEn', ['teaser' => ['fields' => [
            'teaserAssets' => $import['teaserAssets'],
            'teaserText' => 'Imported',
        ]]]);

        $I->assertSame(
            ['teaserEntries' => $preset['teaserEntries'], 'teaserAssets' => $import['teaserAssets'], 'teaserUsers' => $preset['teaserUsers']],
            $this->entryBlock($entry->id, 'corporateEn'),
        );
        $saved = Entry::find()->id($entry->id)->site('corporateEn')->one();
        $I->assertSame('Imported', $saved->getFieldValue('teaser')->getFieldValue('teaserText'));
    }

    public function aBlockFieldTakesNoMoreThanItsMaximum(IntegrationTester $I): void
    {
        $books = [$I->book('One')->id, $I->book('Two')->id];
        $I->preset('features', 'feature', ['teaser' => ['fields' => ['teaserEntries' => $books]]]);
        // The field allows fewer elements now than the preset holds.
        $field = Craft::$app->getFields()->getFieldByHandle('teaserEntries');
        $I->assertInstanceOf(EntriesField::class, $field);
        $field->maxRelations = 1;
        $I->assertTrue(Craft::$app->getFields()->saveField($field));

        $entry = $this->newFeature($I, 'corporateEn');

        $I->assertSame([$books[0]], $this->entryBlock($entry->id, 'corporateEn')['teaserEntries']);
    }

    public function aPresetsBlockFieldHoldsNoMoreThanItsMaximum(IntegrationTester $I): void
    {
        $preset = $I->preset('features', 'feature', ['teaser' => []]);
        $books = array_map(fn(string $title) => $I->book($title)->id, ['One', 'Two', 'Three']);

        $response = $I->postAction('primer/presets/save', [
            'siteId' => $preset->siteId,
            'presetId' => $preset->id,
            'fieldUids' => [$I->layoutElementUid('feature', 'teaser')],
            'fields' => ['teaser' => ['fields' => ['teaserEntries' => $books]]],
        ]);

        $I->assertSame('Couldn’t save preset.', $response['message'] ?? null);
        $I->assertSame(
            ['Teaser entries (none) should contain at most 2 selections.'],
            $response['errors']['teaser.teaserEntries'] ?? print_r($response, true),
        );
        $I->assertSame([], $this->presetBlock($preset->id, 'corporateEn')['teaserEntries']);
    }

    public function aNewPresetWithTheBlockSavesThoughAnInnerFieldNeedsAMinimum(IntegrationTester $I): void
    {
        $field = Craft::$app->getFields()->getFieldByHandle('teaserEntries');
        $I->assertInstanceOf(EntriesField::class, $field);
        $field->minRelations = 1;
        $I->assertTrue(Craft::$app->getFields()->saveField($field));
        $teaser = $I->layoutElementUid('feature', 'teaser');

        // Never saved, the preset can't fill the block yet: its form shows Craft's message instead.
        $new = $I->postAction('primer/presets/save', [
            'siteId' => $I->site('corporateEn')->id,
            'sectionId' => $I->section('features')->id,
            'typeId' => $I->entryType('feature')->id,
            'fieldUids' => [$teaser],
        ]);
        $I->assertSame('Preset saved.', $new['message'] ?? null, print_r($new, true));

        // Once saved, it can, and the minimum holds.
        $saved = $I->postAction('primer/presets/save', [
            'siteId' => $I->site('corporateEn')->id,
            'presetId' => $new['preset']['id'] ?? null,
            'fieldUids' => [$teaser],
        ]);
        $I->assertSame('Couldn’t save preset.', $saved['message'] ?? null);
        $I->assertArrayHasKey('teaser.teaserEntries', $saved['errors'] ?? []);
    }

    public function switchingToATypeWithTheBlockFillsIt(IntegrationTester $I): void
    {
        [$values] = $this->values($I, 'preset');
        $I->preset('features', 'feature', ['teaser' => ['fields' => $values]]);
        $entry = $I->entry('features', 'page', ['title' => 'Launch']);

        $entry->setTypeId($I->entryType('feature')->id);
        $I->saveElement($entry);

        $I->assertSame($values, $this->entryBlock($entry->id, 'corporateEn'));
    }

    public function removingTheBlockEmptiesItsRelationFieldsInEverySite(IntegrationTester $I): void
    {
        [$values] = $this->values($I, 'preset');
        $book = $I->book('Kept');
        $preset = $I->preset('features', 'feature', ['teaser' => ['fields' => $values], 'entriesSite' => [$book->id]], 'corporateEn');

        $this->save($I, $preset->id, 'corporateDe', ['entriesSite']);

        $empty = array_fill_keys(self::INNER, []);
        foreach (self::SITES as $site) {
            $I->assertSame($empty, $this->presetBlock($preset->id, $site), $site);
            $read = Preset::find()->id($preset->id)->site($site)->one();
            $I->assertSame([$book->id], $read->getFieldValue('entriesSite')->ids(), "entriesSite in $site");
        }

        // Chosen again, it starts empty and fills nothing.
        $this->save($I, $preset->id, 'shopFr', ['entriesSite', 'teaser']);
        $I->assertSame($empty, $this->entryBlock($this->newFeature($I, 'corporateEn')->id, 'corporateEn'));
    }

    public function aBlockAddedBackToTheLayoutDoesntBringTheOldValuesBack(IntegrationTester $I): void
    {
        [$values] = $this->values($I, 'preset');
        $preset = $I->preset('features', 'feature', ['teaser' => ['fields' => $values]], 'corporateEn');
        $oldUid = $I->layoutElementUid('feature', 'teaser');

        $this->removeTeaserFromLayout($I);
        $this->addTeaserToLayout($I);
        $I->assertNotSame($oldUid, $I->layoutElementUid('feature', 'teaser'));

        $empty = array_fill_keys(self::INNER, []);
        foreach (self::SITES as $site) {
            $I->assertSame($empty, $this->presetBlock($preset->id, $site), $site);
        }
        // Chosen again, it fills nothing.
        $this->save($I, $preset->id, 'corporateEn', ['teaser']);
        $I->assertSame($empty, $this->entryBlock($this->newFeature($I, 'corporateEn')->id, 'corporateEn'));
    }

    public function aBlockReplacedInOneSaveDoesntBringTheOldValuesBack(IntegrationTester $I): void
    {
        [$values] = $this->values($I, 'preset');
        $preset = $I->preset('features', 'feature', ['teaser' => ['fields' => $values]], 'corporateEn');
        $oldUid = $I->layoutElementUid('feature', 'teaser');

        $type = $I->entryType('feature');
        foreach ($type->getFieldLayout()->getTabs() as $tab) {
            $tab->setElements(array_map(
                fn($element) => $element instanceof CustomField && $element->getField()->handle === 'teaser'
                    ? new CustomField($element->getField(), ['dateAdded' => new DateTime()])
                    : $element,
                $tab->getElements(),
            ));
        }
        $I->saveEntryType($type);
        $I->assertNotSame($oldUid, $I->layoutElementUid('feature', 'teaser'));

        $empty = array_fill_keys(self::INNER, []);
        foreach (self::SITES as $site) {
            $I->assertSame($empty, $this->presetBlock($preset->id, $site), $site);
        }
    }

    public function anInnerFieldAddedBackToTheBlocksLayoutDoesntBringTheOldValueBack(IntegrationTester $I): void
    {
        [$values] = $this->values($I, 'preset');
        $preset = $I->preset('features', 'feature', ['teaser' => ['fields' => $values]], 'corporateEn');

        // A field save, not an entry type save: the block's own layout loses teaserEntries and gets it back.
        $this->setTeaserElements($I, fn(array $elements) => array_values(array_filter(
            $elements,
            fn($element) => !$element instanceof CustomField || $element->getField()->handle !== 'teaserEntries',
        )));
        $this->setTeaserElements($I, fn(array $elements) => [
            ...$elements,
            new CustomField(Craft::$app->getFields()->getFieldByHandle('teaserEntries'), ['dateAdded' => new DateTime()]),
        ]);

        foreach (self::SITES as $site) {
            $I->assertSame([], $this->presetBlock($preset->id, $site)['teaserEntries'], $site);
        }
        $I->assertSame($values['teaserUsers'], $this->presetBlock($preset->id, 'corporateEn')['teaserUsers'], 'The other inner fields stay');
    }

    /**
     * Saves the Teaser block field with its own layout's elements changed.
     *
     * @param callable(FieldLayoutElement[]): FieldLayoutElement[] $change
     */
    private function setTeaserElements(IntegrationTester $I, callable $change): void
    {
        $fields = Craft::$app->getFields();
        $teaser = $fields->getFieldByHandle('teaser');
        $I->assertInstanceOf(ContentBlockField::class, $teaser);
        $tab = $teaser->getFieldLayout()->getTabs()[0];
        $tab->setElements($change($tab->getElements()));
        $I->assertTrue($fields->saveField($teaser));
    }

    private function removeTeaserFromLayout(IntegrationTester $I): void
    {
        $type = $I->entryType('feature');
        foreach ($type->getFieldLayout()->getTabs() as $tab) {
            $tab->setElements(array_values(array_filter(
                $tab->getElements(),
                fn($element) => !$element instanceof CustomField || $element->getField()->handle !== 'teaser',
            )));
        }
        $I->saveEntryType($type);
        $I->assertNull($I->entryType('feature')->getFieldLayout()->getFieldByHandle('teaser'), 'teaser left feature');
    }

    /**
     * Adds the block back as an admin does: a new layout element, with a new UID.
     */
    private function addTeaserToLayout(IntegrationTester $I): void
    {
        $type = $I->entryType('feature');
        $tab = $type->getFieldLayout()->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField(Craft::$app->getFields()->getFieldByHandle('teaser'))]);
        $I->saveEntryType($type);
    }

    /**
     * Saves a preset through the save action, as the preset screen does.
     *
     * @param string[] $handles The chosen fields.
     * @param array<string, mixed> $fields Posted field values.
     */
    private function save(IntegrationTester $I, int $presetId, string $site, array $handles, array $fields = []): void
    {
        $response = $I->postAction('primer/presets/save', [
            'siteId' => $I->site($site)->id,
            'presetId' => $presetId,
            'fieldUids' => array_map(fn(string $handle) => $I->layoutElementUid('feature', $handle), $handles),
            'fields' => $fields,
        ]);

        $I->assertSame('Preset saved.', $response['message'] ?? null, print_r($response, true));
    }

    /**
     * Values for the block's relation fields, one set per name: a book, an image and a user each.
     *
     * @return array<array{teaserEntries: int[], teaserAssets: int[], teaserUsers: int[]}>
     */
    private function values(IntegrationTester $I, string ...$names): array
    {
        return array_map(fn(string $name) => [
            'teaserEntries' => [$I->book("Book $name")->id],
            'teaserAssets' => [$I->image("$name.png")->id],
            'teaserUsers' => [$I->user($name)->id],
        ], $names);
    }

    /**
     * The block's relation field values of a preset in a site.
     *
     * @return array<string, int[]>
     */
    private function presetBlock(int $presetId, string $site): array
    {
        $preset = Preset::find()->id($presetId)->site($site)->status(null)->one();

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
            fn(string $handle) => $block->getFieldValue($handle)->status(null)->limit(null)->ids(),
            self::INNER,
        ));
    }

    /**
     * A saved Feature entry, created in a site.
     *
     * @param array<string, mixed> $values Field values by handle.
     */
    private function newFeature(IntegrationTester $I, string $site, array $values = []): Entry
    {
        $entry = new Entry([
            'sectionId' => $I->section('features')->id,
            'typeId' => $I->entryType('feature')->id,
            'siteId' => $I->site($site)->id,
            'title' => 'Launch',
        ]);
        $entry->setFieldValues($values);
        $I->saveElement($entry);

        return $entry;
    }

    private function newPresetUrl(IntegrationTester $I): string
    {
        return sprintf(
            '/admin/primer/presets/new?sectionId=%d&typeId=%d',
            $I->section('features')->id,
            $I->entryType('feature')->id,
        );
    }
}
