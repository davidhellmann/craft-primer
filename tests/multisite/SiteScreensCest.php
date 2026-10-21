<?php

namespace davidhellmann\primer\tests\multisite;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * The control panel on a multi-site install: every screen is about one site, picked with Craft's site menu.
 */
class SiteScreensCest
{
    public function theIndexShowsThePresetsOfTheRequestedSite(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $I->preset('pagesNone', 'page', ['entriesSite' => [], 'usersNone' => []], 'corporateDe');

        $I->amOnPage('/admin/primer?site=corporateDe');

        $I->seeResponseCodeIs(200);
        $I->see('Page', '[data-section="pagesNone"] [data-preset] .chip');
        $I->see('2 fields', '[data-section="pagesNone"] [data-preset]');
        $I->see('Corporate DE', '#site-crumb');
    }

    public function anEntryTypeWithPresetsInOtherScopesOnlySaysWhere(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $I->preset('pagesNone', 'page', [], 'corporateDe');
        $I->preset('pagesNone', 'page', [], 'shopFr');
        $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');

        $I->amOnPage('/admin/primer?site=corporateEn');

        $I->dontSeeElement('[data-section="pagesNone"] [data-preset]');
        $I->see('Page', '[data-section="pagesNone"] li .chip');
        $I->see('Only in Corporate DE, Shop FR so far', '[data-section="pagesNone"] li');
        // Its chip starts this site's preset, and the entry type isn't offered a second time.
        $I->seeElement(sprintf(
            '[data-section="pagesNone"] li a[href*="primer/presets/new"][href*="sectionId=%d"][href*="site=corporateEn"]',
            $I->section('pagesNone')->id,
        ));
        $I->dontSeeElement('[data-section="pagesNone"] .menu-item');
        // A preset of this site's scope counts its fields, as on a single site.
        $I->see('0 fields', '[data-section="pagesSiteGroup"] [data-preset]');
        $I->dontSee('Only in', '[data-section="pagesSiteGroup"]');
    }

    public function theIndexLinksStayInTheSite(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesAll', 'page', [], 'shopEn');

        $I->amOnPage('/admin/primer?site=shopEn');

        $I->seeElement("[data-preset] a[href*=\"primer/presets/$preset->id\"][href*=\"site=shopEn\"]");
        $I->seeElement('[data-section="pagesNone"] a.menu-item[href*="primer/presets/new"][href*="site=shopEn"]');
    }

    public function theSiteMenuListsTheSitesTheUserMayEdit(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->editor(['corporateEn', 'shopFr']));

        $I->amOnPage('/admin/primer?site=shopFr');

        $I->see('Shop FR', '#site-crumb');
        $I->seeElement('#site-crumb-menu a[data-site-id="' . $I->site('corporateEn')->id . '"]');
        $I->dontSeeElement('#site-crumb-menu a[data-site-id="' . $I->site('corporateDe')->id . '"]');
        $I->dontSeeElement('#site-crumb-menu a[data-site-id="' . $I->site('shopEn')->id . '"]');

        // A site the user may not edit isn't shown: Craft falls back to one they may.
        $I->amOnPage('/admin/primer?site=shopEn');
        $I->dontSee('Shop EN', '#site-crumb');
    }

    public function thePresetScreenShowsThePresetInTheRequestedSite(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        [$english, $german] = [$I->book('English book'), $I->book('German book')];
        $preset = $I->preset('pagesAll', 'page', ['entriesSite' => [$english->id]], 'corporateEn');
        $inGerman = Preset::find()->id($preset->id)->site('corporateDe')->one();
        $inGerman->setFieldValue('entriesSite', [$german->id]);
        $I->saveElement($inGerman);

        $I->amOnPage("/admin/primer/presets/$preset->id?site=corporateDe");

        $I->seeResponseCodeIs(200);
        $I->see('Corporate DE', '#site-crumb');
        $I->seeInField('siteId', (string)$I->site('corporateDe')->id);
        $I->see('German book', '[data-primer-field] .elementselect');
        $I->dontSee('English book', '[data-primer-field] .elementselect');
    }

    public function thePresetScreensSiteMenuListsTheSitesTheUserMayEdit(IntegrationTester $I): void
    {
        $preset = $I->preset('pagesAll', 'page', [], 'corporateEn');
        $I->amLoggedInAs($I->editor(['corporateEn', 'shopFr']));

        $I->amOnPage("/admin/primer/presets/$preset->id?site=shopFr");

        $I->see('Shop FR', '#site-crumb');
        $I->seeElement('#site-crumb-menu a[data-site-id="' . $I->site('corporateEn')->id . '"]');
        $I->dontSeeElement('#site-crumb-menu li:not(.hidden) a[data-site-id="' . $I->site('shopEn')->id . '"]');
    }

    public function aPresetOfAnotherScopeOpensThisSitesPresetOfTheEntryType(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $english = $I->preset('pagesNone', 'page', [], 'corporateEn');
        $german = $I->preset('pagesNone', 'page', [], 'corporateDe');

        $I->amOnPage("/admin/primer/presets/$english->id?site=corporateDe");

        $I->seeCurrentUrlMatches("~admin/primer/presets/$german->id[?&]site=corporateDe$~");
    }

    public function aPresetOfAnotherScopeStartsThisSitesPresetWhenThereIsNone(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $english = $I->preset('pagesNone', 'page', ['entriesSite' => [], 'usersNone' => []], 'corporateEn');

        $I->amOnPage("/admin/primer/presets/$english->id?site=shopFr");

        $I->see('New preset: Page', 'h1');
        $I->seeInField('siteId', (string)$I->site('shopFr')->id);
        // The field choice is the same in every scope, so this site's preset starts with it.
        foreach (['entriesSite', 'usersNone'] as $handle) {
            $uid = $I->layoutElementUid('page', $handle);
            $I->seeElement("input[name=\"fieldUids[]\"][value=\"$uid\"]");
            $I->seeElement("[data-primer-field=\"$uid\"]:not(.hidden)");
        }
    }

    public function aSlideoutOpensThisSitesPresetInPlace(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $german = $I->preset('pagesNone', 'page', [], 'corporateDe');

        // A slideout can't follow a redirect to a page: it gets the existing preset itself.
        $screen = $I->slideout('primer/presets/edit', [
            'sectionId' => $I->section('pagesNone')->id,
            'typeId' => $I->entryType('page')->id,
            'site' => 'corporateDe',
        ]);

        $I->assertMatchesRegularExpression('~name="[a-z]+\[presetId\]" value="' . $german->id . '"~', $screen['content']);
        $I->assertMatchesRegularExpression('~name="[a-z]+\[siteId\]" value="' . $I->site('corporateDe')->id . '"~', $screen['content']);
        // Its section crumb opens the section's slideout in the same site.
        $I->assertStringContainsString('&quot;site&quot;:&quot;corporateDe&quot;', $screen['extraToolbarItems'] ?? '');
    }

    public function theSectionPageIsAboutTheRequestedSite(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $I->preset('pagesNone', 'page', [], 'corporateDe');

        $I->amOnPage('/admin/primer/sections/pagesNone?site=corporateEn');

        $I->seeResponseCodeIs(200);
        $I->see('Corporate EN', '#site-crumb');
        $I->see('Only in Corporate DE so far', '[data-primer-type="page"]');
        $I->dontSeeElement('[data-primer-type="page"] [data-primer-delete]');
        $nav = json_decode($I->grabAttributeFrom('[data-primer-type="page"] th a', 'data-primer-nav'), true);
        $I->assertSame('corporateEn', $nav['params']['site']);
    }

    public function savingStoresThePresetInTheSiteAndReturnsToItsIndex(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $book = $I->book('Shop book');
        $uid = $I->layoutElementUid('page', 'entriesSite');
        $I->amOnPage(sprintf('/admin/primer/presets/new?sectionId=%d&typeId=%d&site=shopFr', $I->section('pagesNone')->id, $I->entryType('page')->id));

        $I->submitForm('#main-form', ['fieldUids' => [$uid], 'fields' => ['entriesSite' => [$book->id]]]);

        $preset = Preset::find()->sectionId($I->section('pagesNone')->id)->site('shopFr')->one();
        $I->assertNotNull($preset);
        $I->assertSame([$book->id], $preset->getFieldValue('entriesSite')->ids());
        $I->assertNull(Preset::find()->sectionId($I->section('pagesNone')->id)->site('corporateEn')->one());
        $I->seeCurrentUrlMatches('~admin/primer[?&]site=shopFr$~');
    }

    public function savingInASiteTheUserMayNotEditIsForbidden(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->editor(['corporateEn']));
        $I->amOnPage(sprintf('/admin/primer/presets/new?sectionId=%d&typeId=%d', $I->section('pagesNone')->id, $I->entryType('page')->id));

        $I->submitForm('#main-form', ['siteId' => $I->site('corporateDe')->id]);

        $I->seeResponseCodeIs(403);
        $I->assertNull(Preset::find()->site('*')->one());
    }

    public function deletingAPresetDeletesItInEveryScope(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->editor(['corporateDe']));
        $german = $I->preset('pagesNone', 'page', [], 'corporateDe');
        $I->preset('pagesNone', 'page', [], 'shopFr');

        $I->postAction('primer/presets/delete', [
            'presetId' => $german->id,
            'siteId' => $I->site('corporateDe')->id,
        ]);

        $I->seeResponseCodeIs(200);
        $I->assertNull(Preset::find()->site('*')->one());
    }

    public function deletingInASiteTheUserMayNotEditIsForbidden(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->editor(['corporateEn']));
        $german = $I->preset('pagesNone', 'page', [], 'corporateDe');

        $I->postAction('primer/presets/delete', [
            'presetId' => $german->id,
            'siteId' => $I->site('corporateDe')->id,
        ]);

        $I->seeResponseCodeIs(403);
        $I->assertNotNull(Preset::find()->id($german->id)->site('corporateDe')->one());
    }

    public function addFieldIsSearchableWithMoreThanFiveFieldsToAdd(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        // Page has twelve relation fields.
        $handles = [];
        foreach (['entries', 'assets', 'users'] as $type) {
            foreach (['None', 'Site', 'SiteGroup', 'Language'] as $method) {
                $handles[] = $type . $method;
            }
        }
        $search = '[data-primer-add-menu] .menu--disclosure[data-with-search-input]';

        // Seven chosen, five to add.
        $preset = $I->preset('pagesNone', 'page', array_fill_keys(array_slice($handles, 0, 7), []), 'corporateEn');
        $I->amOnPage("/admin/primer/presets/$preset->id");
        $I->dontSeeElement($search);

        // Six chosen, six to add.
        $preset = $I->preset('pagesNone', 'page', array_fill_keys(array_slice($handles, 0, 6), []), 'shopEn');
        $I->amOnPage("/admin/primer/presets/$preset->id?site=shopEn");
        $I->seeElement($search);
    }

    public function craftRendersEveryAssetsFieldWithoutAWarning(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $page = $I->preset('pagesAll', 'page', ['assetsNone' => [], 'assetsSite' => [], 'assetsSiteGroup' => [], 'assetsLanguage' => []]);
        $feature = $I->preset('features', 'feature', ['teaser' => []]);

        foreach ([$page, $feature] as $preset) {
            $I->amOnPage("/admin/primer/presets/$preset->id");

            $I->seeResponseCodeIs(200);
            $I->seeElement('[data-primer-field] .elementselect');
            $I->dontSee('invalid volume');
        }
    }
}
