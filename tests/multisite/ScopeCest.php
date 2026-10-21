<?php

namespace davidhellmann\primer\tests\multisite;

use Codeception\Attribute\DataProvider;
use Codeception\Example;
use Craft;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\Plugin;
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

        Plugin::getInstance()->presets->deleteInAllScopes($corporate);

        $I->assertSame([], Preset::find()->sectionId($I->section('pagesNone')->id)->site('*')->ids());
        $I->assertSame([$other->id], Preset::find()->site('*')->unique()->ids());
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
