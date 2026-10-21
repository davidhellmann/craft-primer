<?php

namespace davidhellmann\primer\tests\integration;

use craft\helpers\Json;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * The section page: a section's entry types, each with its preset's chosen fields, as a page and as a slideout.
 */
class SectionScreenCest
{
    public function _before(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
    }

    public function theSectionPageListsItsEntryTypesWithTheChosenFields(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id], 'writers' => [$I->admin()->id]]);

        $I->amOnPage('/admin/primer/sections/news');

        $I->seeResponseCodeIs(200);
        $I->see('News', 'h1');
        $article = '[data-primer-type="article"]';
        $I->seeElement("$article a[href$=\"primer/presets/$preset->id\"]");
        $I->see('Article', "$article a");
        $I->see('Topics', "$article [data-primer-fields]");
        $I->see('Writers', "$article [data-primer-fields]");
        $I->dontSee('Hero image', "$article [data-primer-fields]");
    }

    public function anEntryTypeWithoutAPresetOffersAddPreset(IntegrationTester $I): void
    {
        $I->preset('news', 'article');
        $newUrl = sprintf('primer/presets/new&sectionId=%d&typeId=%d', $I->section('news')->id, $I->entryType('note')->id);

        $I->amOnPage('/admin/primer/sections/news');

        $note = '[data-primer-type="note"]';
        $I->see('Add preset', "$note a.btn.add");
        $I->seeElement("$note a.btn.add[href\$=\"$newUrl\"]");
        $I->dontSeeElement("$note [data-primer-delete]");
        $I->dontSeeElement("$note [data-primer-fields]");
    }

    public function aPresetWithoutChosenFieldsSaysSo(IntegrationTester $I): void
    {
        $I->preset('news', 'article');

        $I->amOnPage('/admin/primer/sections/news');

        $I->see('No fields yet', '[data-primer-type="article"] [data-primer-fields]');
    }

    public function entryTypeIconsTakeTheTypesOwnColourAndFieldIconsStayNeutral(IntegrationTester $I): void
    {
        $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id]]);

        $I->amOnPage('/admin/primer/sections/news');

        // Article is blue with a newspaper icon in the fixture; Note has neither.
        $I->seeElement('[data-primer-type="article"] th .cp-icon.blue');
        $I->dontSeeElement('[data-primer-type="note"] th .cp-icon');
        $I->seeElement('[data-primer-type="article"] [data-primer-fields] .cp-icon.primer-neutral');
        $I->dontSeeElement('[data-primer-type="article"] [data-primer-fields] .cp-icon.blue');
    }

    public function eachPresetLineCanBeDeleted(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage('/admin/primer/sections/news');

        $delete = Json::decode($I->grabAttributeFrom('[data-primer-type="article"] a.delete.icon', 'data-primer-delete'));
        $I->assertSame($preset->id, $delete['presetId']);
        $I->assertSame($preset->siteId, $delete['siteId']);
        $I->assertSame(
            'Are you sure you want to delete “Article”?',
            $I->grabAttributeFrom('[data-primer-type="article"] a.delete.icon', 'data-primer-confirm'),
        );
    }

    public function theEntryTypesOpenAsPageOnClickAndAsSlideoutOnDoubleClick(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article');

        $I->amOnPage('/admin/primer/sections/news');

        $I->assertSame(
            ['action' => 'primer/presets/edit', 'params' => ['presetId' => $preset->id]],
            Json::decode($I->grabAttributeFrom('[data-primer-type="article"] th a', 'data-primer-nav')),
        );
        $I->assertSame(
            ['action' => 'primer/presets/edit', 'params' => ['sectionId' => $I->section('news')->id, 'typeId' => $I->entryType('note')->id]],
            Json::decode($I->grabAttributeFrom('[data-primer-type="note"] a.btn.add', 'data-primer-nav')),
        );
    }

    public function thePageCrumbsLeadBackToPrimer(IntegrationTester $I): void
    {
        $I->amOnPage('/admin/primer/sections/news');

        $I->seeLink('Primer', '?p=admin/primer');
        $I->seeElement('#crumb-list a[href$="?p=admin/primer"]');
    }

    public function anUnknownSectionIsNotFound(IntegrationTester $I): void
    {
        $I->amOnPage('/admin/primer/sections/nope');

        $I->seeResponseCodeIs(404);
    }

    public function theSlideoutIsTheSameScreenWithCrumbsInItsHeader(IntegrationTester $I): void
    {
        $preset = $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id]]);

        $screen = $I->slideout('primer/presets/section', ['sectionHandle' => 'news']);

        $I->assertStringContainsString('data-primer-type="article"', $screen['content']);
        $I->assertStringContainsString("primer/presets/$preset->id", $screen['content']);
        $I->assertStringContainsString('Topics', $screen['content']);
        $I->assertStringEndsWith('primer/sections/news', $screen['editUrl']);
        // Not a form: the slideout gets Close, no Save.
        $I->assertNull($screen['action']);
        // Craft shows no title in a slideout; the header carries the crumbs, the section as the current one.
        $I->assertStringContainsString('primer-slideout-crumbs', $screen['extraToolbarItems']);
        $I->assertStringContainsString('aria-current="page"', $screen['extraToolbarItems']);
        $I->assertStringContainsString('News', $screen['extraToolbarItems']);
        // The shared behaviour comes along, for a slideout opened on a page that doesn't have it yet.
        $I->assertStringContainsString('primerNav', $screen['bodyHtml']);
    }
}
