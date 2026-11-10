<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;

/**
 * Primer fills the site an entry is created in. Craft then propagates the entry as always; other sites' presets stay
 * out of it.
 */
class PropagationTest extends TestCase
{
    use Content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pro();
    }

    public function testAPropagatedEntryIsNotFilledFromTheOtherSitesPreset(): void
    {
        $book = $this->book('German book');

        // Chosen everywhere, empty in Corporate EN, filled in Corporate DE (a per-site field).
        $preset = $this->preset('pagesAll', 'page', ['entriesSite' => []], 'corporateEn');
        $german = $this->stored($preset, 'corporateDe');
        $german->setFieldValue('entriesSite', [$book->id]);
        $this->assertTrue(Elements::saveElement($german));

        $entry = $this->entry('pagesAll', 'page', ['title' => 'Created in Corporate EN'], 'corporateEn');

        // Craft copied the empty field to Corporate DE; Corporate DE's preset stays out of it.
        $inGerman = Entry::find()->id($entry->id)->site('corporateDe')->status(null)->one();
        $this->assertSame([], $this->ids($inGerman->getFieldValue('entriesSite')));

        // The same entry type created in Corporate DE does get it.
        $created = $this->entry('pagesAll', 'page', ['title' => 'Created in Corporate DE'], 'corporateDe');
        $this->assertSame([$book->id], $this->ids(Entry::find()->id($created->id)->site('corporateDe')->status(null)->one()->getFieldValue('entriesSite')));
    }

    public function testASiteEnabledLaterGetsWhatCraftCopiesNotAPreset(): void
    {
        $section = $this->section('pagesAll');
        $settings = $section->getSiteSettings();
        $shopFr = $this->site('shopFr')->id;
        $section->setSiteSettings(array_diff_key($settings, [$shopFr => true]));
        $this->assertTrue(Sections::saveSection($section));

        // Corporate EN fills entriesSite and leaves usersSite empty; Shop FR's preset fills both.
        [$english, $french] = [$this->book('English book'), $this->book('French book')];
        $this->preset('pagesAll', 'page', ['entriesSite' => [$english->id], 'usersSite' => []], 'corporateEn');
        $entry = $this->entry('pagesAll', 'page', ['title' => 'Before Shop FR'], 'corporateEn');

        // Shop FR's preset, saved without validation: the section isn't enabled for Shop FR yet.
        $frenchPreset = new Preset([
            'sectionId' => $section->id,
            'typeId' => $this->entryType('page')->id,
            'siteId' => $shopFr,
        ]);
        $frenchPreset->setFieldUids([$this->layoutElementUid('page', 'entriesSite'), $this->layoutElementUid('page', 'usersSite')]);
        $frenchPreset->setFieldValues(['entriesSite' => [$french->id], 'usersSite' => [$this->admin()->id]]);
        $this->assertTrue(Elements::saveElement($frenchPreset, false));

        // Craft's queue job, which runs at once in the tests, brings the entry to Shop FR.
        $section = $this->section('pagesAll');
        $section->setSiteSettings([
            ...$section->getSiteSettings(),
            $shopFr => new SectionSiteSettings(['siteId' => $shopFr, 'enabledByDefault' => true, 'hasUrls' => false]),
        ]);
        $this->assertTrue(Sections::saveSection($section));

        $inFrench = Entry::find()->id($entry->id)->site('shopFr')->status(null)->one();
        $this->assertNotNull($inFrench, 'Craft propagated the entry to Shop FR');
        $this->assertSame([$english->id], $this->ids($inFrench->getFieldValue('entriesSite')), 'Craft copied entriesSite');
        $this->assertSame([], $this->ids($inFrench->getFieldValue('usersSite')), 'Primer filled nothing');
    }
}
