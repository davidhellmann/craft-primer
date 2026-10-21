<?php

namespace davidhellmann\primer\tests\multisite;

use Codeception\Attribute\DataProvider;
use Codeception\Example;
use Craft;
use craft\db\Table;
use craft\enums\PropagationMethod;
use craft\helpers\Db;
use craft\models\Section_SiteSettings as SectionSiteSettings;
use DateTime;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * A preset spans the sites a new entry of its section would be saved to, one preset element per scope.
 */
class ScopeCest
{
    #[DataProvider('scopes')]
    public function aPresetSpansTheSitesANewEntryWouldBeSavedTo(IntegrationTester $I, Example $example): void
    {
        $preset = $I->preset($example['section'], 'page', [], $example['site']);

        $sites = Preset::find()->id($preset->id)->site('*')->select('elements_sites.siteId')->column();
        $handles = array_map(fn($id) => Craft::$app->getSites()->getSiteById((int)$id)->handle, $sites);

        $I->assertEqualsCanonicalizing($example['scope'], $handles);
    }

    public function eachScopeHasAPresetOfItsOwn(IntegrationTester $I): void
    {
        $corporate = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');

        // Corporate DE is in the same scope: the entry type already has its preset there.
        $second = new Preset([
            'sectionId' => $I->section('pagesSiteGroup')->id,
            'typeId' => $I->entryType('page')->id,
            'siteId' => $I->site('corporateDe')->id,
        ]);
        $I->assertFalse(Craft::$app->getElements()->saveElement($second));
        $I->assertTrue($second->hasErrors('typeId'));

        $shop = $I->preset('pagesSiteGroup', 'page', [], 'shopEn');
        $I->assertNotSame($corporate->id, $shop->id);
    }

    public function aSiteAddedToTheSectionLaterGetsAPresetOfItsOwn(IntegrationTester $I): void
    {
        $section = $I->section('pagesAll');
        $settings = $section->getSiteSettings();
        $shopFr = $I->site('shopFr')->id;
        $this->setSectionSites($I, 'pagesAll', array_diff_key($settings, [$shopFr => true]));
        $first = $I->preset('pagesAll', 'page', [], 'corporateEn');

        $added = clone $settings[$shopFr];
        $added->id = null;
        $this->setSectionSites($I, 'pagesAll', [...array_diff_key($settings, [$shopFr => true]), $shopFr => $added]);
        $second = $I->preset('pagesAll', 'page', [], 'shopFr');

        $I->assertSame([
            'corporateEn' => [$first->id],
            'corporateDe' => [$first->id],
            'shopEn' => [$first->id],
            'shopFr' => [$second->id],
        ], $this->presetsBySite($I, 'pagesAll'));

        // Saved again, the first stays out of the site the second holds.
        $I->assertTrue(Craft::$app->getElements()->saveElement(Preset::find()->id($first->id)->site('corporateEn')->one()));
        $I->assertSame([$second->id], $this->presetsBySite($I, 'pagesAll')['shopFr']);
    }

    public function aChangedPropagationMethodKeepsOnePresetPerSite(IntegrationTester $I): void
    {
        $english = $I->preset('pagesNone', 'page', [], 'corporateEn');
        $german = $I->preset('pagesNone', 'page', [], 'corporateDe');

        $section = $I->section('pagesNone');
        $section->propagationMethod = PropagationMethod::All;
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section));

        // A new preset takes the sites no preset holds yet; a saved one keeps out of the others'.
        $shop = $I->preset('pagesNone', 'page', [], 'shopEn');
        $I->assertTrue(Craft::$app->getElements()->saveElement(Preset::find()->id($english->id)->site('corporateEn')->one()));

        $I->assertSame([
            'corporateEn' => [$english->id],
            'corporateDe' => [$german->id],
            'shopEn' => [$shop->id],
            'shopFr' => [$shop->id],
        ], $this->presetsBySite($I, 'pagesNone'));
    }

    public function aPresetInASiteAnotherPresetHoldsIsInvalid(IntegrationTester $I): void
    {
        $I->preset('pagesNone', 'page', [], 'corporateDe');
        $section = $I->section('pagesNone');
        $section->propagationMethod = PropagationMethod::All;
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section));

        $preset = new Preset([
            'sectionId' => $section->id,
            'typeId' => $I->entryType('page')->id,
            'siteId' => $I->site('corporateDe')->id,
        ]);

        $I->assertFalse(Craft::$app->getElements()->saveElement($preset));
        $I->assertTrue($preset->hasErrors('typeId'));
    }

    public function shouldTwoPresetsShareASiteTheOldestApplies(IntegrationTester $I): void
    {
        $older = $I->preset('pagesNone', 'page', ['usersNone' => [$I->user('older')->id]], 'corporateEn');
        $newer = new Preset([
            'sectionId' => $I->section('pagesNone')->id,
            'typeId' => $I->entryType('page')->id,
            'siteId' => $I->site('corporateEn')->id,
        ]);
        $newer->setFieldUids([$I->layoutElementUid('page', 'usersNone')]);
        $newer->setFieldValue('usersNone', [$I->user('newer')->id]);
        // Without validation, as an import might; the newer one also gets the later date.
        $I->assertTrue(Craft::$app->getElements()->saveElement($newer, false));
        Db::update(Table::ELEMENTS, ['dateCreated' => Db::prepareDateForDb(new DateTime('-1 day'))], ['id' => $older->id]);

        $entry = $I->entry('pagesNone', 'page');

        $I->assertSame($older->getFieldValue('usersNone')->ids(), $entry->getFieldValue('usersNone')->ids());
    }

    public function aPresetNeedsASiteTheSectionIsEnabledFor(IntegrationTester $I): void
    {
        $section = $I->section('pagesAll');
        $settings = $section->getSiteSettings();
        unset($settings[$I->site('shopFr')->id]);
        $section->setSiteSettings($settings);
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section));

        $preset = new Preset([
            'sectionId' => $section->id,
            'typeId' => $I->entryType('page')->id,
            'siteId' => $I->site('shopFr')->id,
        ]);

        $I->assertFalse(Craft::$app->getElements()->saveElement($preset));
        $I->assertTrue($preset->hasErrors('siteId'));
    }

    public function theFieldChoiceIsTheSameInEveryScope(IntegrationTester $I): void
    {
        $corporate = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $shop = $I->preset('pagesSiteGroup', 'page', [], 'shopEn');
        $choice = [$I->layoutElementUid('page', 'entriesSite'), $I->layoutElementUid('page', 'usersNone')];

        $corporate->setFieldUids($choice);
        $I->saveElement($corporate);

        foreach (['corporateEn', 'corporateDe', 'shopEn', 'shopFr'] as $site) {
            $read = Preset::find()->sectionId($corporate->sectionId)->typeId($corporate->typeId)->site($site)->one();
            $I->assertSame($choice, $read->getFieldUids(), $site);
        }
        $I->assertSame($choice, Preset::find()->id($shop->id)->site('shopFr')->one()->getFieldUids());
    }

    public function anotherSectionOrEntryTypeKeepsItsOwnChoice(IntegrationTester $I): void
    {
        $other = $I->preset('pagesNone', 'page', ['usersSite' => []], 'corporateEn');

        $I->preset('pagesSiteGroup', 'page', ['entriesSite' => []], 'corporateEn');

        $I->assertSame([$I->layoutElementUid('page', 'usersSite')], Preset::find()->id($other->id)->one()->getFieldUids());
    }

    public function deletingAPresetDeletesItInEveryScope(IntegrationTester $I): void
    {
        $corporate = $I->preset('pagesNone', 'page', [], 'corporateEn');
        $I->preset('pagesNone', 'page', [], 'shopFr');
        $other = $I->preset('pagesSiteGroup', 'page', [], 'corporateEn');

        Craft::$app->getElements()->deleteElement($corporate, true);

        $I->assertSame([], Preset::find()->sectionId($I->section('pagesNone')->id)->site('*')->ids());
        $I->assertSame([$other->id], Preset::find()->site('*')->unique()->ids());
    }

    /**
     * @param array<int, SectionSiteSettings> $settings
     */
    private function setSectionSites(IntegrationTester $I, string $sectionHandle, array $settings): void
    {
        $section = $I->section($sectionHandle);
        $section->setSiteSettings($settings);
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section));
    }

    /**
     * The presets of the section's Page entry type in each site, in Craft's site order.
     *
     * @return array<string, int[]>
     */
    private function presetsBySite(IntegrationTester $I, string $sectionHandle): array
    {
        $bySite = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $ids = Preset::find()->sectionId($I->section($sectionHandle)->id)->typeId($I->entryType('page')->id)->siteId($site->id)->ids();
            if ($ids) {
                $bySite[$site->handle] = $ids;
            }
        }

        return $bySite;
    }

    /**
     * @return array<string, array{section: string, site: string, scope: string[]}>
     */
    protected function scopes(): array
    {
        return [
            'all' => ['section' => 'pagesAll', 'site' => 'shopFr', 'scope' => ['corporateEn', 'corporateDe', 'shopEn', 'shopFr']],
            'site group' => ['section' => 'pagesSiteGroup', 'site' => 'shopFr', 'scope' => ['shopEn', 'shopFr']],
            'language' => ['section' => 'pagesLanguage', 'site' => 'shopEn', 'scope' => ['corporateEn', 'shopEn']],
            'none' => ['section' => 'pagesNone', 'site' => 'corporateDe', 'scope' => ['corporateDe']],
            'custom' => ['section' => 'pagesCustom', 'site' => 'corporateDe', 'scope' => ['corporateDe']],
        ];
    }
}
