<?php

namespace davidhellmann\primer\tests\integration;

use Craft;
use craft\fieldlayoutelements\CustomField;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * The Preset element: stored by Craft like any element, its values in Craft's own content and relations.
 */
class PresetCest
{
    public function aPresetIsSavedAndReadBack(IntegrationTester $I): void
    {
        [$design, $code] = [$I->topic('Design'), $I->topic('Code')];
        $topicsUid = $I->layoutElementUid('article', 'topics');

        $preset = new Preset([
            'sectionId' => $I->section('news')->id,
            'typeId' => $I->entryType('article')->id,
        ]);
        $preset->setFieldUids([$topicsUid]);
        $preset->setFieldValue('topics', [$code->id, $design->id]);
        $I->saveElement($preset);

        $read = Preset::find()->id($preset->id)->one();
        $I->assertInstanceOf(Preset::class, $read);
        $I->assertSame($I->section('news')->id, $read->sectionId);
        $I->assertSame($I->entryType('article')->id, $read->typeId);
        $I->assertSame([$topicsUid], $read->getFieldUids());
        $I->assertSame([$code->id, $design->id], $read->getFieldValue('topics')->ids());
    }

    public function itsFieldLayoutHoldsTheEntryTypesRelationFieldsUnderTheSameUids(IntegrationTester $I): void
    {
        $article = $I->entryType('article')->getFieldLayout();
        $I->assertTrue($article->getFieldByHandle('topics')->layoutElement->required);
        $I->assertNotNull($article->getFieldByHandle('heroImage')->layoutElement->getUserCondition());
        $preset = new Preset(['sectionId' => $I->section('news')->id, 'typeId' => $I->entryType('article')->id]);

        $elements = $preset->getFieldLayout()->getCustomFieldElements();

        // Summary (plain text) is left out; the Entries, Assets and Users fields come in layout order.
        $I->assertSame(['topics', 'heroImage', 'writers', 'relatedArticles'], array_map(fn(CustomField $e) => $e->attribute(), $elements));
        foreach ($elements as $element) {
            $I->assertSame($I->layoutElementUid('article', $element->attribute()), $element->uid);
            // Topics is required in Article, and Hero image is shown to admins only; a preset needs neither.
            $I->assertFalse($element->required);
            $I->assertNull($element->getUserCondition());
            $I->assertNull($element->getElementCondition());
        }
    }

    public function aPresetNeedsASectionAndAnEntryType(IntegrationTester $I): void
    {
        $preset = new Preset();

        $I->assertFalse(Craft::$app->getElements()->saveElement($preset));
        $I->assertTrue($preset->hasErrors('sectionId'));
        $I->assertTrue($preset->hasErrors('typeId'));
    }

    public function theEntryTypeMustBelongToTheSection(IntegrationTester $I): void
    {
        $preset = new Preset(['sectionId' => $I->section('news')->id, 'typeId' => $I->entryType('topic')->id]);

        $I->assertFalse(Craft::$app->getElements()->saveElement($preset));
        $I->assertTrue($preset->hasErrors('typeId'));
    }

    public function anEntryTypeHasOnePresetPerSectionAndSite(IntegrationTester $I): void
    {
        $I->preset('news', 'article');
        $second = new Preset(['sectionId' => $I->section('news')->id, 'typeId' => $I->entryType('article')->id]);

        $I->assertFalse(Craft::$app->getElements()->saveElement($second));
        $I->assertTrue($second->hasErrors('typeId'));

        // Another entry type of the same section is fine.
        $I->preset('news', 'note');
    }

    public function aChosenFieldKeepsItsMaximumNumberOfRelations(IntegrationTester $I): void
    {
        $articles = [$I->entry('news', 'note'), $I->entry('news', 'note'), $I->entry('news', 'note')];
        $preset = new Preset(['sectionId' => $I->section('news')->id, 'typeId' => $I->entryType('article')->id]);
        $preset->setFieldUids([$I->layoutElementUid('article', 'relatedArticles')]);
        // Related articles allows two.
        $preset->setFieldValue('relatedArticles', array_map(fn($entry) => $entry->id, $articles));

        $I->assertFalse(Craft::$app->getElements()->saveElement($preset));
        $I->assertTrue($preset->hasErrors('relatedArticles'));

        $preset->setFieldValue('relatedArticles', [$articles[0]->id, $articles[1]->id]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($preset));
    }

    public function aFieldThatIsNotChosenHoldsNoValue(IntegrationTester $I): void
    {
        $topic = $I->topic('Design');
        $preset = new Preset(['sectionId' => $I->section('news')->id, 'typeId' => $I->entryType('article')->id]);
        $preset->setFieldUids([$I->layoutElementUid('article', 'writers')]);
        // The form posts every field, chosen or not; only the chosen ones keep their value.
        $preset->setFieldValues(['topics' => [$topic->id], 'writers' => [$I->admin()->id]]);
        $I->saveElement($preset);

        $read = Preset::find()->id($preset->id)->one();
        $I->assertSame([], $read->getFieldValue('topics')->ids());
        $I->assertCount(1, $read->getFieldValue('writers')->ids());
    }

    public function presetsAreFoundBySectionAndEntryType(IntegrationTester $I): void
    {
        $article = $I->preset('news', 'article');
        $note = $I->preset('news', 'note');

        $I->assertSame([$article->id], Preset::find()->sectionId($I->section('news')->id)->typeId($I->entryType('article')->id)->ids());
        $I->assertEqualsCanonicalizing([$article->id, $note->id], Preset::find()->sectionId($I->section('news')->id)->ids());
        $I->assertSame([], Preset::find()->sectionId($I->section('topics')->id)->ids());
    }
}
