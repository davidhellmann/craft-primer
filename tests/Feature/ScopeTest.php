<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Enums\PropagationMethod;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Query;
use DateTime;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A preset spans the sites a new entry of its section would be saved to, one preset element per scope.
 */
class ScopeTest extends TestCase
{
    use Content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pro();
    }

    /**
     * @param string[] $scope
     */
    #[DataProvider('scopes')]
    public function testAPresetSpansTheSitesANewEntryWouldBeSavedTo(string $section, string $site, array $scope): void
    {
        $preset = $this->preset($section, 'page', [], $site);

        $handles = Preset::find()->id($preset->id)->site('*')->get()
            ->map(fn(Preset $inSite) => $inSite->getSite()->handle)
            ->all();

        $this->assertEqualsCanonicalizing($scope, $handles);
    }

    /**
     * @return array<string, array{string, string, string[]}>
     */
    public static function scopes(): array
    {
        return [
            'all' => ['pagesAll', 'shopFr', ['corporateEn', 'corporateDe', 'shopEn', 'shopFr']],
            'site group' => ['pagesSiteGroup', 'shopFr', ['shopEn', 'shopFr']],
            'language' => ['pagesLanguage', 'shopEn', ['corporateEn', 'shopEn']],
            'none' => ['pagesNone', 'corporateDe', ['corporateDe']],
            'custom' => ['pagesCustom', 'corporateDe', ['corporateDe']],
        ];
    }

    public function testEachScopeHasAPresetOfItsOwn(): void
    {
        $corporate = $this->preset('pagesSiteGroup', 'page', [], 'corporateEn');

        // Corporate DE is in the same scope: the entry type already has its preset there.
        $second = new Preset([
            'sectionId' => $this->section('pagesSiteGroup')->id,
            'typeId' => $this->entryType('page')->id,
            'siteId' => $this->site('corporateDe')->id,
        ]);
        $this->assertFalse(Elements::saveElement($second));
        $this->assertTrue($second->errors()->has('typeId'));

        $shop = $this->preset('pagesSiteGroup', 'page', [], 'shopEn');
        $this->assertNotSame($corporate->id, $shop->id);
    }

    public function testASiteAddedToTheSectionLaterGetsAPresetOfItsOwn(): void
    {
        $settings = $this->section('pagesAll')->getSiteSettings();
        $shopFr = $this->site('shopFr')->id;
        $this->setSectionSites('pagesAll', array_diff_key($settings, [$shopFr => true]));
        $first = $this->preset('pagesAll', 'page', [], 'corporateEn');

        $this->setSectionSites('pagesAll', [
            ...array_diff_key($settings, [$shopFr => true]),
            $shopFr => new SectionSiteSettings(['siteId' => $shopFr, 'enabledByDefault' => true, 'hasUrls' => false]),
        ]);
        $second = $this->preset('pagesAll', 'page', [], 'shopFr');

        $this->assertSame([
            'corporateEn' => [$first->id],
            'corporateDe' => [$first->id],
            'shopEn' => [$first->id],
            'shopFr' => [$second->id],
        ], $this->presetsBySite('pagesAll'));

        // Saved again, the first stays out of the site the second holds.
        $this->assertTrue(Elements::saveElement($this->stored($first, 'corporateEn')));
        $this->assertSame([$second->id], $this->presetsBySite('pagesAll')['shopFr']);
    }

    public function testAChangedPropagationMethodKeepsOnePresetPerSite(): void
    {
        $english = $this->preset('pagesNone', 'page', [], 'corporateEn');
        $german = $this->preset('pagesNone', 'page', [], 'corporateDe');

        $section = $this->section('pagesNone');
        $section->propagationMethod = PropagationMethod::All;
        $this->assertTrue(Sections::saveSection($section));

        // A new preset takes the sites no preset holds yet; a saved one keeps out of the others'.
        $shop = $this->preset('pagesNone', 'page', [], 'shopEn');
        $this->assertTrue(Elements::saveElement($this->stored($english, 'corporateEn')));

        $this->assertSame([
            'corporateEn' => [$english->id],
            'corporateDe' => [$german->id],
            'shopEn' => [$shop->id],
            'shopFr' => [$shop->id],
        ], $this->presetsBySite('pagesNone'));
    }

    public function testAPresetInASiteAnotherPresetHoldsIsInvalid(): void
    {
        $this->preset('pagesNone', 'page', [], 'corporateDe');
        $section = $this->section('pagesNone');
        $section->propagationMethod = PropagationMethod::All;
        $this->assertTrue(Sections::saveSection($section));

        $preset = new Preset([
            'sectionId' => $section->id,
            'typeId' => $this->entryType('page')->id,
            'siteId' => $this->site('corporateDe')->id,
        ]);

        $this->assertFalse(Elements::saveElement($preset));
        $this->assertTrue($preset->errors()->has('typeId'));
    }

    public function testShouldTwoPresetsShareASiteTheOldestApplies(): void
    {
        $older = $this->preset('pagesNone', 'page', ['usersNone' => [$this->user('older')->id]], 'corporateEn');
        $newer = new Preset([
            'sectionId' => $this->section('pagesNone')->id,
            'typeId' => $this->entryType('page')->id,
            'siteId' => $this->site('corporateEn')->id,
        ]);
        $newer->setFieldUids([$this->layoutElementUid('page', 'usersNone')]);
        $newer->setFieldValue('usersNone', [$this->user('newer')->id]);
        // Without validation, as an import might; the newer one also gets the later date.
        $this->assertTrue(Elements::saveElement($newer, false));
        DB::table(Table::ELEMENTS)->where('id', $older->id)->update(['dateCreated' => Query::prepareDateForDb(new DateTime('-1 day'))]);

        $entry = $this->entry('pagesNone', 'page', [], 'corporateEn');

        $this->assertSame($this->ids($older->getFieldValue('usersNone')), $this->ids($entry->getFieldValue('usersNone')));
    }

    public function testAPresetNeedsASiteTheSectionIsEnabledFor(): void
    {
        $section = $this->section('pagesAll');
        $settings = $section->getSiteSettings();
        unset($settings[$this->site('shopFr')->id]);
        $this->setSectionSites('pagesAll', $settings);

        $preset = new Preset([
            'sectionId' => $section->id,
            'typeId' => $this->entryType('page')->id,
            'siteId' => $this->site('shopFr')->id,
        ]);

        $this->assertFalse(Elements::saveElement($preset));
        $this->assertTrue($preset->errors()->has('siteId'));
    }

    public function testTheFieldChoiceIsTheSameInEveryScope(): void
    {
        $corporate = $this->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $shop = $this->preset('pagesSiteGroup', 'page', [], 'shopEn');
        $choice = [$this->layoutElementUid('page', 'entriesSite'), $this->layoutElementUid('page', 'usersNone')];

        $corporate->setFieldUids($choice);
        $this->assertTrue(Elements::saveElement($corporate));

        foreach (['corporateEn', 'corporateDe', 'shopEn', 'shopFr'] as $site) {
            $read = Preset::find()->sectionId($corporate->sectionId)->typeId($corporate->typeId)->site($site)->one();
            $this->assertSame($choice, $read->getFieldUids(), $site);
        }
        $this->assertSame($choice, $this->stored($shop, 'shopFr')->getFieldUids());
    }

    public function testAnotherSectionOrEntryTypeKeepsItsOwnChoice(): void
    {
        $other = $this->preset('pagesNone', 'page', ['usersSite' => []], 'corporateEn');

        $this->preset('pagesSiteGroup', 'page', ['entriesSite' => []], 'corporateEn');

        $this->assertSame([$this->layoutElementUid('page', 'usersSite')], $this->stored($other)->getFieldUids());
    }

    public function testDeletingAPresetDeletesItInEveryScope(): void
    {
        $corporate = $this->preset('pagesNone', 'page', [], 'corporateEn');
        $this->preset('pagesNone', 'page', [], 'shopFr');
        $other = $this->preset('pagesSiteGroup', 'page', [], 'corporateEn');

        Elements::deleteElement($corporate, true);

        $this->assertSame([], Preset::find()->sectionId($this->section('pagesNone')->id)->site('*')->ids());
        $this->assertSame([$other->id], Preset::find()->site('*')->unique()->ids());
    }

    /**
     * @param array<int, SectionSiteSettings> $settings
     */
    private function setSectionSites(string $sectionHandle, array $settings): void
    {
        $section = $this->section($sectionHandle);
        $section->setSiteSettings($settings);
        $this->assertTrue(Sections::saveSection($section));
        Sections::refreshSections();
    }

    /**
     * The presets of the section's Page entry type in each site, in Craft's site order.
     *
     * @return array<string, int[]>
     */
    private function presetsBySite(string $sectionHandle): array
    {
        $bySite = [];
        foreach (Sites::getAllSites() as $site) {
            $ids = Preset::find()->sectionId($this->section($sectionHandle)->id)->typeId($this->entryType('page')->id)->siteId($site->id)->ids();
            if ($ids) {
                $bySite[$site->handle] = $ids;
            }
        }

        return $bySite;
    }
}
