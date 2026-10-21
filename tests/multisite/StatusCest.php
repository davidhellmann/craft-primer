<?php

namespace davidhellmann\primer\tests\multisite;

use Craft;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\helpers\Json;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * A preset switched on or off per site: the lightswitch of the index and section page, and
 * Craft's status field in the preset's sidebar.
 */
class StatusCest
{
    private const ALL_SITES = ['corporateEn', 'corporateDe', 'shopEn', 'shopFr'];

    public function theLightswitchSwitchesOnlyTheCurrentSiteAndSaysSo(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $book = $I->book();
        $preset = $I->preset('pagesSiteGroup', 'page', ['entriesSite' => [$book->id], 'usersNone' => [$I->admin()->id]], 'corporateEn');

        $answer = $I->postAction('primer/presets/toggle', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateDe')->id,
            'enabled' => '0',
        ]);

        $I->assertSame('Preset switched off for Corporate DE.', $answer['message'] ?? null);
        $I->assertFalse($this->isOn($preset->id, 'corporateDe'));
        $I->assertTrue($this->isOn($preset->id, 'corporateEn'));
        // Its values stay, in the switched-off site too.
        foreach (['corporateEn', 'corporateDe'] as $site) {
            $inSite = Preset::find()->id($preset->id)->site($site)->one();
            $I->assertSame([$book->id], $inSite->getFieldValue('entriesSite')->status(null)->ids(), "entriesSite in $site");
            $I->assertSame([$I->admin()->id], $inSite->getFieldValue('usersNone')->status(null)->ids(), "usersNone in $site");
        }

        $answer = $I->postAction('primer/presets/toggle', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateDe')->id,
            'enabled' => '1',
        ]);

        $I->assertSame('Preset switched on for Corporate DE.', $answer['message'] ?? null);
        $I->assertTrue($this->isOn($preset->id, 'corporateDe'));
    }

    public function aPresetSwitchedOffInASiteOfItsOwnIsNotAppliedUntilSwitchedOnAgain(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $book = $I->book();
        $preset = $I->preset('pagesNone', 'page', ['entriesSite' => [$book->id]], 'corporateDe');
        $toggle = fn(string $on) => $I->postAction('primer/presets/toggle', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateDe')->id,
            'enabled' => $on,
        ]);

        $toggle('0');
        $I->assertFalse($this->isOn($preset->id, 'corporateDe'));
        $I->assertSame([], $this->newEntryIds($I, 'pagesNone', 'corporateDe'));

        $toggle('1');
        $I->assertTrue($this->isOn($preset->id, 'corporateDe'));
        $I->assertSame([$book->id], $this->newEntryIds($I, 'pagesNone', 'corporateDe'));
    }

    public function aPresetOffAsAWholeStaysOffInTheSitesNotSwitchedOnAfterItsScopeGrew(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesNone', 'page', [], 'corporateEn');
        $toggle = fn(string $site, string $on) => $I->postAction('primer/presets/toggle', [
            'presetId' => $preset->id,
            'siteId' => $I->site($site)->id,
            'enabled' => $on,
        ]);
        // Off in a one-site scope: Craft keeps that in `enabled`.
        $toggle('corporateEn', '0');

        // The section now propagates to every site, and the preset spans them all once saved.
        $section = $I->section('pagesNone');
        $section->propagationMethod = PropagationMethod::All;
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section));
        $I->assertTrue(Craft::$app->getElements()->saveElement(Preset::find()->id($preset->id)->site('corporateEn')->one()));
        foreach (self::ALL_SITES as $site) {
            $I->assertFalse($this->isOn($preset->id, $site), "$site before");
        }

        $toggle('corporateDe', '1');

        $I->assertTrue($this->isOn($preset->id, 'corporateDe'));
        foreach (['corporateEn', 'shopEn', 'shopFr'] as $site) {
            $I->assertFalse($this->isOn($preset->id, $site), $site);
        }
    }

    public function savingTheStatusOfASiteOutsideTheScopeChangesNothing(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesNone', 'page', [], 'corporateEn');
        $I->postAction('primer/presets/toggle', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateEn')->id,
            'enabled' => '0',
        ]);

        $I->postAction('primer/presets/save', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateEn')->id,
            'enabledForSite' => [$I->site('shopFr')->id => '1'],
        ]);

        $I->seeResponseCodeIs(200);
        $I->assertFalse($this->isOn($preset->id, 'corporateEn'));
    }

    public function switchingASiteTheUserMayNotEditIsForbidden(IntegrationTester $I): void
    {
        $preset = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $I->amLoggedInAs($I->editor(['corporateEn']));

        $I->postAction('primer/presets/toggle', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateDe')->id,
            'enabled' => '0',
        ]);

        $I->seeResponseCodeIs(403);
        $I->assertTrue($this->isOn($preset->id, 'corporateDe'));
    }

    public function savingWithTheStatusFieldSetsEverySiteOfTheScope(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');

        $I->postAction('primer/presets/save', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateEn')->id,
            'enabledForSite' => [$I->site('corporateEn')->id => '', $I->site('corporateDe')->id => '1'],
        ]);

        $I->seeResponseCodeIs(200);
        $I->assertFalse($this->isOn($preset->id, 'corporateEn'));
        $I->assertTrue($this->isOn($preset->id, 'corporateDe'));
    }

    public function savingWithASiteOnSwitchesThePresetOn(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesNone', 'page', [], 'corporateDe');
        $I->postAction('primer/presets/toggle', ['presetId' => $preset->id, 'siteId' => $I->site('corporateDe')->id, 'enabled' => '0']);
        $I->assertFalse(Preset::find()->id($preset->id)->site('corporateDe')->one()->enabled);

        $I->postAction('primer/presets/save', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateDe')->id,
            'enabledForSite' => [$I->site('corporateDe')->id => '1'],
        ]);

        $I->assertTrue($this->isOn($preset->id, 'corporateDe'));
    }

    public function aNewPresetSavedWithASiteOffStartsOffThere(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());

        $I->postAction('primer/presets/save', [
            'siteId' => $I->site('corporateEn')->id,
            'sectionId' => $I->section('pagesAll')->id,
            'typeId' => $I->entryType('page')->id,
            'enabledForSite' => [
                $I->site('corporateEn')->id => '1',
                $I->site('corporateDe')->id => '1',
                $I->site('shopEn')->id => '',
                $I->site('shopFr')->id => '1',
            ],
        ]);

        $preset = Preset::find()->sectionId($I->section('pagesAll')->id)->site('corporateEn')->one();
        $I->assertNotNull($preset);
        $I->assertSame(
            ['corporateEn' => true, 'corporateDe' => true, 'shopEn' => false, 'shopFr' => true],
            array_combine(['corporateEn', 'corporateDe', 'shopEn', 'shopFr'], array_map(fn(string $site) => $this->isOn($preset->id, $site), ['corporateEn', 'corporateDe', 'shopEn', 'shopFr'])),
        );
    }

    public function savingTheStatusOfASiteTheUserMayNotEditIsForbidden(IntegrationTester $I): void
    {
        $preset = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $I->amLoggedInAs($I->editor(['corporateEn']));

        $I->postAction('primer/presets/save', [
            'presetId' => $preset->id,
            'siteId' => $I->site('corporateEn')->id,
            'enabledForSite' => [$I->site('corporateEn')->id => '1', $I->site('corporateDe')->id => ''],
        ]);

        $I->seeResponseCodeIs(403);
        $I->assertTrue($this->isOn($preset->id, 'corporateDe'));
    }

    public function theSidebarHasAMasterSwitchAndOneSwitchPerSiteOfTheScope(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $I->postAction('primer/presets/toggle', ['presetId' => $preset->id, 'siteId' => $I->site('corporateDe')->id, 'enabled' => '0']);

        $I->amOnPage("/admin/primer/presets/$preset->id?site=corporateEn");

        $I->see('Enabled for all sites', '[data-primer-status] label');
        // Mixed: the master switch is neither on nor off.
        $I->seeElement('[data-primer-status-all] .lightswitch.indeterminate[aria-checked="mixed"]');
        $I->seeElement('[data-primer-status-site] .lightswitch.on');
        $I->see('Corporate EN', '[data-primer-status-site] label');
        $I->see('Corporate DE', '[data-primer-status-site] label');
        $I->seeElement(sprintf('input[name="enabledForSite[%d]"][value="1"]', $I->site('corporateEn')->id));
        $I->seeElement(sprintf('input[name="enabledForSite[%d]"][value=""]', $I->site('corporateDe')->id));
        $I->dontSeeElement(sprintf('input[name="enabledForSite[%d]"]', $I->site('shopEn')->id));
    }

    public function theMasterSwitchIsOnWhenEverySiteIs(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');

        $I->amOnPage("/admin/primer/presets/$preset->id?site=corporateEn");

        $I->seeElement('[data-primer-status-all] .lightswitch.on');
        $I->dontSeeElement('[data-primer-status-site] .lightswitch:not(.on)');
    }

    public function aPresetWithOneSiteInScopeHasASingleEnabledSwitch(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesNone', 'page', [], 'corporateDe');
        $I->postAction('primer/presets/toggle', ['presetId' => $preset->id, 'siteId' => $I->site('corporateDe')->id, 'enabled' => '0']);

        $I->amOnPage("/admin/primer/presets/$preset->id?site=corporateDe");

        $I->see('Enabled', '[data-primer-status] label');
        $I->dontSee('Enabled for all sites', '[data-primer-status]');
        $I->dontSeeElement('[data-primer-status-all]');
        $I->seeElement(sprintf('[data-primer-status] input[name="enabledForSite[%d]"][value=""]', $I->site('corporateDe')->id));
    }

    public function theSidebarListsOnlyTheSitesTheUserMayEdit(IntegrationTester $I): void
    {
        $preset = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $I->amLoggedInAs($I->editor(['corporateEn']));

        $I->amOnPage("/admin/primer/presets/$preset->id?site=corporateEn");

        $I->dontSeeElement('[data-primer-status-all]');
        $I->seeElement(sprintf('[data-primer-status] input[name="enabledForSite[%d]"]', $I->site('corporateEn')->id));
        $I->dontSeeElement(sprintf('[data-primer-status] input[name="enabledForSite[%d]"]', $I->site('corporateDe')->id));
    }

    public function aNewPresetStartsOnInEverySite(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());

        $I->amOnPage(sprintf('/admin/primer/presets/new?sectionId=%d&typeId=%d&site=corporateEn', $I->section('pagesAll')->id, $I->entryType('page')->id));

        $I->seeElement('[data-primer-status-all] .lightswitch.on');
        $I->seeNumberOfElements('[data-primer-status-site] .lightswitch.on', 4);
    }

    public function eachIndexLineHasALightswitchForTheCurrentSite(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $I->postAction('primer/presets/toggle', ['presetId' => $preset->id, 'siteId' => $I->site('corporateDe')->id, 'enabled' => '0']);

        $I->amOnPage('/admin/primer?site=corporateDe');

        $line = '[data-section="pagesSiteGroup"] [data-preset]';
        $I->assertSame(
            ['presetId' => $preset->id, 'siteId' => $I->site('corporateDe')->id],
            Json::decode($I->grabAttributeFrom("$line .lightswitch", 'data-primer-toggle')),
        );
        $I->seeElement("$line .lightswitch:not(.on)");
        // A switched-off line is dimmed.
        $I->seeElement("$line.primer-off");

        $I->amOnPage('/admin/primer?site=corporateEn');

        $I->seeElement("$line .lightswitch.on");
        $I->dontSeeElement("$line.primer-off");
    }

    public function anIndexLineOnlyInOtherScopesHasNoLightswitch(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $I->preset('pagesNone', 'page', [], 'corporateDe');

        $I->amOnPage('/admin/primer?site=corporateEn');

        $I->see('Only in Corporate DE so far', '[data-section="pagesNone"] li');
        $I->dontSeeElement('[data-section="pagesNone"] .lightswitch');
    }

    public function eachSectionPageLineHasALightswitchForTheCurrentSite(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
        $preset = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $I->postAction('primer/presets/toggle', ['presetId' => $preset->id, 'siteId' => $I->site('corporateDe')->id, 'enabled' => '0']);

        $I->amOnPage('/admin/primer/sections/pagesSiteGroup?site=corporateDe');

        $line = '[data-primer-type="page"]';
        $I->assertSame(
            ['presetId' => $preset->id, 'siteId' => $I->site('corporateDe')->id],
            Json::decode($I->grabAttributeFrom("$line .lightswitch", 'data-primer-toggle')),
        );
        $I->seeElement("$line .lightswitch:not(.on)");
        $I->seeElement("$line.primer-off");

        $I->amOnPage('/admin/primer/sections/pagesSiteGroup?site=corporateEn');

        $I->seeElement("$line .lightswitch.on");
        $I->dontSeeElement("$line.primer-off");
    }

    public function aSectionPageLineWithoutAPresetHasNoLightswitch(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());

        $I->amOnPage('/admin/primer/sections/pagesNone?site=corporateEn');

        $I->seeElement('[data-primer-type="page"]');
        $I->dontSeeElement('[data-primer-type="page"] .lightswitch');
    }

    /**
     * Whether the preset applies in the site: on, and on for that site.
     */
    private function isOn(int $presetId, string $site): bool
    {
        return Preset::find()->id($presetId)->site($site)->one()->isOn();
    }

    /**
     * The Entries (site) IDs of a new entry created in the site.
     *
     * @return int[]
     */
    private function newEntryIds(IntegrationTester $I, string $section, string $site): array
    {
        $entry = new Entry([
            'sectionId' => $I->section($section)->id,
            'typeId' => $I->entryType('page')->id,
            'siteId' => $I->site($site)->id,
            'title' => 'New',
        ]);
        $I->saveElement($entry);

        return Entry::find()->id($entry->id)->site($site)->status(null)->one()->getFieldValue('entriesSite')->status(null)->ids();
    }
}
