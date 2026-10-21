<?php

namespace davidhellmann\primer\tests\multisite;

use Craft;
use craft\elements\Entry;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * Primer fills the saving site only; Craft's propagation of a new entry to the other sites is never filled again.
 */
class PropagationCest
{
    public function aPropagatedEntryIsNotFilledFromTheOtherSitesPreset(IntegrationTester $I): void
    {
        $book = new Entry([
            'sectionId' => $I->section('library')->id,
            'typeId' => $I->entryType('book')->id,
            'title' => 'German book',
        ]);
        $book->setEnabledForSite(array_fill_keys(Craft::$app->getSites()->getAllSiteIds(), true));
        $I->saveElement($book);

        // Chosen everywhere, empty in Corporate EN, filled in Corporate DE (a per-site field).
        $preset = $I->preset('pagesAll', 'page', ['entriesSite' => []], 'corporateEn');
        $german = Preset::find()->id($preset->id)->site('corporateDe')->one();
        $german->setFieldValue('entriesSite', [$book->id]);
        $I->saveElement($german);

        $entry = new Entry([
            'sectionId' => $I->section('pagesAll')->id,
            'typeId' => $I->entryType('page')->id,
            'siteId' => $I->site('corporateEn')->id,
            'title' => 'Created in Corporate EN',
        ]);
        $I->saveElement($entry);

        // Craft copied the empty field to Corporate DE; Corporate DE's preset stays out of it.
        $inGerman = Entry::find()->id($entry->id)->site('corporateDe')->status(null)->one();
        $I->assertSame([], $inGerman->getFieldValue('entriesSite')->ids());

        // The same entry type created in Corporate DE does get it.
        $created = new Entry([
            'sectionId' => $I->section('pagesAll')->id,
            'typeId' => $I->entryType('page')->id,
            'siteId' => $I->site('corporateDe')->id,
            'title' => 'Created in Corporate DE',
        ]);
        $I->saveElement($created);
        $I->assertSame([$book->id], Entry::find()->id($created->id)->site('corporateDe')->status(null)->one()->getFieldValue('entriesSite')->ids());
    }

    public function aSiteEnabledLaterGetsWhatCraftCopiesNotAPreset(IntegrationTester $I): void
    {
        $section = $I->section('pagesAll');
        $settings = $section->getSiteSettings();
        $shopFr = $I->site('shopFr')->id;
        $section->setSiteSettings(array_diff_key($settings, [$shopFr => true]));
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section));

        // Corporate EN fills entriesSite and leaves usersSite empty; Shop FR's preset fills both.
        [$english, $french] = [$I->book('English book'), $I->book('French book')];
        $I->preset('pagesAll', 'page', ['entriesSite' => [$english->id], 'usersSite' => []], 'corporateEn');
        $entry = $I->entry('pagesAll', 'page', ['title' => 'Before Shop FR']);

        $section = $I->section('pagesAll');
        $added = clone $settings[$shopFr];
        $added->id = null;
        $section->setSiteSettings([...$section->getSiteSettings(), $shopFr => $added]);
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section));
        $I->preset('pagesAll', 'page', ['entriesSite' => [$french->id], 'usersSite' => [$I->admin()->id]], 'shopFr');

        // Craft's queue job brings the entry to Shop FR.
        Craft::$app->getQueue()->run();

        $inFrench = Entry::find()->id($entry->id)->site('shopFr')->status(null)->one();
        $I->assertNotNull($inFrench, 'Craft propagated the entry to Shop FR');
        $I->assertSame([$english->id], $inFrench->getFieldValue('entriesSite')->status(null)->ids(), 'Craft copied entriesSite');
        $I->assertSame([], $inFrench->getFieldValue('usersSite')->ids(), 'Primer filled nothing');
    }
}
