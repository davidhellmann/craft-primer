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

    public function aSectionOpensAsPageOnClickAndAsSlideoutOnDoubleClick(IntegrationTester $I): void
    {
        $I->amOnPage('/admin/primer');

        $link = '[data-section="news"] th a';
        $I->see('News', $link);
        $I->seeElement($link . '[href$="primer/sections/news"]');
        $I->assertSame(
            ['action' => 'primer/presets/section', 'params' => ['sectionHandle' => 'news'], 'form' => false],
            Json::decode($I->grabAttributeFrom($link, 'data-primer-nav')),
        );
    }

    public function aPresetChipOpensAsPageOnClickAndAsSlideoutOnDoubleClick(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage('/admin/primer');

        $chip = '[data-section="news"] [data-preset] a.primer-index-chip';
        $I->seeElement($chip . '[href$="primer/presets/' . $preset->id . '"]');
        $I->assertSame(
            ['action' => 'primer/presets/edit', 'params' => ['presetId' => $preset->id]],
            Json::decode($I->grabAttributeFrom($chip, 'data-primer-nav')),
        );
    }

    public function eachPresetLineInTheIndexCanBeDeleted(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage('/admin/primer');

        $delete = '[data-section="news"] [data-preset] a.delete.icon';
        $I->assertSame(
            ['presetId' => $preset->id, 'siteId' => $preset->siteId],
            Json::decode($I->grabAttributeFrom($delete, 'data-primer-delete')),
        );
        $I->assertSame('Are you sure you want to delete “Article”?', $I->grabAttributeFrom($delete, 'data-primer-confirm'));
        // Back to the index after deleting there.
        $I->assertStringEndsWith('primer', $I->grabAttributeFrom($delete, 'data-primer-redirect'));
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

    public function thePresetPageCrumbsLeadToItsSection(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage("/admin/primer/presets/$preset->id");

        $I->seeElement('#crumb-list a[href$="?p=admin/primer"]');
        $I->seeElement('#crumb-list a[href$="?p=admin/primer/sections/news"]');
        $I->see('News', '#crumb-list a');
    }

    public function deletePresetSitsInTheActionMenu(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage("/admin/primer/presets/$preset->id");

        $item = '.menu-item[data-primer-delete]';
        $I->see('Delete preset', $item);
        $I->assertSame(
            ['presetId' => $preset->id, 'siteId' => $preset->siteId],
            Json::decode($I->grabAttributeFrom($item, 'data-primer-delete')),
        );
        $I->assertSame('Are you sure you want to delete “Article”?', $I->grabAttributeFrom($item, 'data-primer-confirm'));
        // Back to the section page after deleting (hashed, as Craft wants a posted redirect).
        $I->assertStringEndsWith('primer/sections/news', $I->grabAttributeFrom($item, 'data-primer-redirect'));
    }

    public function aNewPresetHasNothingToDelete(IntegrationTester $I): void
    {
        $I->amOnPage($this->newPresetUrl($I, 'article'));

        $I->dontSeeElement('[data-primer-delete]');
    }

    public function thePresetSlideoutIsTheSameScreen(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id]]);

        $screen = $I->slideout('primer/presets/edit', ['presetId' => $preset->id]);

        $I->assertSame('primer/presets/save', $screen['action']);
        $I->assertStringEndsWith("primer/presets/$preset->id", $screen['editUrl']);
        $I->assertStringContainsString('data-primer-field="' . $I->layoutElementUid('article', 'topics') . '"', $screen['content']);
        $I->assertStringContainsString('Design', $screen['content']);
        // Inputs are namespaced for the slideout.
        $I->assertStringContainsString('name="' . $screen['namespace'] . '[fieldUids][]"', $screen['content']);
        // Craft leaves destructive actions out of a slideout's ⋯ menu; Delete preset is on the page.
        $I->assertEmpty($screen['actionMenu']);
    }

    public function thePresetSlideoutHeaderHasTheSectionAndTheEntryTypeAsCrumbs(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $screen = $I->slideout('primer/presets/edit', ['presetId' => $preset->id]);

        $crumbs = $screen['extraToolbarItems'];
        $I->assertStringContainsString('primer-slideout-crumbs', $crumbs);
        // The section links to its page and opens its slideout on double-click.
        $I->assertMatchesRegularExpression('~<a class="crumb-link" href="[^"]*primer/sections/news"~', $crumbs);
        $nav = htmlspecialchars(Json::encode([
            'action' => 'primer/presets/section',
            'params' => ['sectionHandle' => 'news'],
            'form' => false,
        ]), ENT_QUOTES);
        $I->assertStringContainsString("data-primer-nav=\"$nav\"", $crumbs);
        // The entry type is the current crumb, with its icon in its own colour.
        $I->assertMatchesRegularExpression('~aria-current="page"><span class="cp-icon puny blue">.*</span><span>Article</span>~s', $crumbs);
    }

    public function thePageHasNoSlideoutCrumbs(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage("/admin/primer/presets/$preset->id");

        $I->dontSeeElement('.primer-slideout-crumbs');
    }

    public function savingInASlideoutAnswersWithTheMessage(IntegrationTester $I): void
    {
        $topicsUid = $I->layoutElementUid('article', 'topics');

        $response = $I->postAction('primer/presets/save', [
            'siteId' => $I->section('news')->getSiteIds()[0],
            'sectionId' => $I->section('news')->id,
            'typeId' => $I->entryType('article')->id,
            'fieldUids' => [$topicsUid],
        ]);

        $I->seeResponseCodeIs(200);
        $I->assertSame('Preset saved.', $response['message']);
        $I->assertNotNull(Preset::find()->sectionId($I->section('news')->id)->one());
    }

    public function aFailedSaveInASlideoutAnswersWithTheMessage(IntegrationTester $I): void
    {
        $notes = [$I->entry('news', 'note'), $I->entry('news', 'note'), $I->entry('news', 'note')];

        $response = $I->postAction('primer/presets/save', [
            'siteId' => $I->section('news')->getSiteIds()[0],
            'sectionId' => $I->section('news')->id,
            'typeId' => $I->entryType('article')->id,
            'fieldUids' => [$I->layoutElementUid('article', 'relatedArticles')],
            'fields' => ['relatedArticles' => array_map(fn($note) => $note->id, $notes)],
        ]);

        $I->seeResponseCodeIs(400);
        $I->assertSame('Couldn’t save preset.', $response['message']);
    }

    private function newPresetUrl(IntegrationTester $I, string $typeHandle): string
    {
        return sprintf('/admin/primer/presets/new?sectionId=%d&typeId=%d', $I->section('news')->id, $I->entryType($typeHandle)->id);
    }
}
