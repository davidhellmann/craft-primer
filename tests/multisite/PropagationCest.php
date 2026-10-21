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
}
