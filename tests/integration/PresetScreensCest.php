<?php

namespace davidhellmann\primer\tests\integration;

use craft\helpers\Json;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * The control panel: the index of sections and their presets, and the preset screen.
 */
class PresetScreensCest
{
    public function _before(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
    }

    public function theIndexListsEachSectionWithItsPresets(IntegrationTester $I): void
    {
        $topic = $I->topic('Design');
        $I->preset('news', 'article', ['topics' => [$topic->id], 'writers' => [$I->admin()->id]]);

        $I->amOnPage('/admin/primer');

        $I->seeResponseCodeIs(200);
        $I->see('News', '[data-section="news"]');
        $I->see('Topics', '[data-section="topics"]');
        // The preset's entry type as Craft's chip, with its field count.
        $I->see('Article', '[data-section="news"] [data-preset] .chip');
        $I->see('2 fields', '[data-section="news"] [data-preset]');
        $I->dontSeeElement('[data-section="topics"] [data-preset]');
    }

    public function addEntryTypeOffersTheTypesWithoutAPreset(IntegrationTester $I): void
    {
        $I->preset('news', 'article');

        $I->amOnPage('/admin/primer');

        $I->see('Add entry type', '[data-section="news"] button');
        $I->see('Note', '[data-section="news"] .menu-item');
        $I->dontSee('Article', '[data-section="news"] .menu-item');
        $I->seeElement(sprintf(
            '[data-section="news"] a.menu-item[href$="primer/presets/new&sectionId=%d&typeId=%d"]',
            $I->section('news')->id,
            $I->entryType('note')->id,
        ));
        $I->see('Topic', '[data-section="topics"] .menu-item');
    }

    public function aNewPresetStartsWithNoFieldsAndOffersTheRelationFields(IntegrationTester $I): void
    {
        $I->amOnPage($this->newPresetUrl($I, 'article'));

        $I->seeResponseCodeIs(200);
        $I->see('New preset: Article', 'h1');
        $I->seeInField('sectionId', (string)$I->section('news')->id);
        $I->seeInField('typeId', (string)$I->entryType('article')->id);
        $I->dontSeeElement('[data-primer-field]:not(.hidden)');
        foreach (['Topics', 'Hero image', 'Writers', 'Related articles'] as $label) {
            $I->see($label, '[data-primer-add-menu] .menu-item');
        }
        $I->dontSee('Summary', '[data-primer-add-menu] .menu-item');
    }

    public function thePresetPageShowsTheChosenFieldsThroughCraft(IntegrationTester $I): void
    {
        $topic = $I->topic('Design');
        $preset = $I->preset('news', 'article', ['topics' => [$topic->id]]);

        $I->amOnPage("/admin/primer/presets/$preset->id");

        $I->seeResponseCodeIs(200);
        $I->see('Article', 'h1');
        // Craft's own Entries input, with the preset's element selected. The page script puts an × into each
        // field's heading from the remove button template.
        $chosen = '[data-primer-field="' . $I->layoutElementUid('article', 'topics') . '"]:not(.hidden)';
        $I->seeElement("$chosen .elementselect");
        $I->see('Design', "$chosen .elementselect");
        $I->seeElement('template[data-primer-remove-template] [data-primer-remove]');
        $I->seeElement('input[name="fieldUids[]"][value="' . $I->layoutElementUid('article', 'topics') . '"]');
        $I->dontSee('Topics', '[data-primer-add-menu] li:not(.hidden) .menu-item');
        $I->see('Writers', '[data-primer-add-menu] li:not(.hidden) .menu-item');
    }

    public function saveAndContinueEditingIsTheSaveShortcut(IntegrationTester $I): void
    {
        $I->amOnPage($this->newPresetUrl($I, 'article'));

        $actions = Json::decode($I->grabAttributeFrom('#main-form', 'data-actions'));

        $I->assertCount(1, $actions);
        $I->assertSame('Save and continue editing', $actions[0]['label']);
        $I->assertTrue($actions[0]['shortcut']);
    }

    public function savingTheFormStoresThePreset(IntegrationTester $I): void
    {
        $topic = $I->topic('Design');
        $topicsUid = $I->layoutElementUid('article', 'topics');
        $I->amOnPage($this->newPresetUrl($I, 'article'));

        $I->submitForm('#main-form', [
            'fieldUids' => [$topicsUid],
            'fields' => ['topics' => [$topic->id]],
        ]);

        $preset = Preset::find()->sectionId($I->section('news')->id)->typeId($I->entryType('article')->id)->one();
        $I->assertNotNull($preset);
        $I->assertSame([$topicsUid], $preset->getFieldUids());
        $I->assertSame([$topic->id], $preset->getFieldValue('topics')->ids());
        $I->seeCurrentUrlMatches('~admin/primer$~');
    }

    public function aPresetThatFailsValidationComesBackWithItsErrors(IntegrationTester $I): void
    {
        $notes = [$I->entry('news', 'note'), $I->entry('news', 'note'), $I->entry('news', 'note')];
        $relatedUid = $I->layoutElementUid('article', 'relatedArticles');
        $I->amOnPage($this->newPresetUrl($I, 'article'));

        // Related articles allows two.
        $I->submitForm('#main-form', [
            'fieldUids' => [$relatedUid],
            'fields' => ['relatedArticles' => array_map(fn($note) => $note->id, $notes)],
        ]);

        $I->see('New preset: Article', 'h1');
        $I->seeElement('[data-primer-field="' . $relatedUid . '"]:not(.hidden) .errors');
        $I->assertNull(Preset::find()->sectionId($I->section('news')->id)->one());
    }

    public function aSecondPresetForTheSameEntryTypeOpensTheFirst(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage($this->newPresetUrl($I, 'article'));

        $I->seeCurrentUrlMatches("~admin/primer/presets/$preset->id$~");
    }

    private function newPresetUrl(IntegrationTester $I, string $typeHandle): string
    {
        return sprintf('/admin/primer/presets/new?sectionId=%d&typeId=%d', $I->section('news')->id, $I->entryType($typeHandle)->id);
    }
}
