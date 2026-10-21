<?php

namespace davidhellmann\primer\tests\integration;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\fields\Entries;
use davidhellmann\primer\elements\db\PresetQuery;
use davidhellmann\primer\tests\Support\IntegrationTester;
use RuntimeException;
use yii\base\Event;

/**
 * A new entry starts with its preset's elements in the chosen fields it leaves empty.
 */
class ApplyCest
{
    public function aNewEntryGetsThePresetsElements(IntegrationTester $I): void
    {
        [$design, $code] = [$I->topic('Design'), $I->topic('Code')];
        $I->preset('news', 'article', [
            'topics' => [$code->id, $design->id],
            'writers' => [$I->admin()->id],
        ]);

        $entry = $I->entry('news', 'article', ['title' => 'Hello']);

        $saved = Entry::find()->id($entry->id)->one();
        $I->assertSame([$code->id, $design->id], $saved->getFieldValue('topics')->ids());
        $I->assertSame([$I->admin()->id], $saved->getFieldValue('writers')->ids());
        $I->assertSame([], $saved->getFieldValue('relatedArticles')->ids());
    }

    public function aFieldTheEntryAlreadyFillsStaysUntouched(IntegrationTester $I): void
    {
        [$design, $code, $craft] = [$I->topic('Design'), $I->topic('Code'), $I->topic('Craft')];
        $I->preset('news', 'article', ['topics' => [$code->id, $design->id], 'writers' => [$I->admin()->id]]);

        $entry = $I->entry('news', 'article', ['topics' => [$craft->id]]);

        $saved = Entry::find()->id($entry->id)->one();
        $I->assertSame([$craft->id], $saved->getFieldValue('topics')->ids());
        // The other chosen field was empty, so it still gets the preset's.
        $I->assertSame([$I->admin()->id], $saved->getFieldValue('writers')->ids());
    }

    public function aFieldTakesNoMoreThanItsMaximum(IntegrationTester $I): void
    {
        $topics = [$I->topic('Design'), $I->topic('Code'), $I->topic('Craft')];
        $I->preset('news', 'article', ['topics' => array_map(fn(Entry $topic) => $topic->id, $topics)]);
        // The field allows fewer elements now than the preset holds.
        $field = Craft::$app->getFields()->getFieldByHandle('topics');
        $I->assertInstanceOf(Entries::class, $field);
        $field->maxRelations = 2;
        $I->assertTrue(Craft::$app->getFields()->saveField($field));

        $entry = $I->entry('news', 'article');

        // Craft limits a relation field's query to its maximum; lift the limit to see what was stored.
        $saved = Entry::find()->id($entry->id)->one();
        $I->assertSame([$topics[0]->id, $topics[1]->id], $saved->getFieldValue('topics')->limit(null)->ids());
    }

    public function anAssetsFieldIsFilledToo(IntegrationTester $I): void
    {
        $image = $I->image('hero.png');
        $I->preset('news', 'article', ['heroImage' => [$image->id]]);

        $entry = $I->entry('news', 'article');

        $I->assertSame([$image->id], Entry::find()->id($entry->id)->one()->getFieldValue('heroImage')->ids());
    }

    public function anExistingEntryIsNotFilledAgain(IntegrationTester $I): void
    {
        $topic = $I->topic('Design');
        $I->preset('news', 'article', ['topics' => [$topic->id]]);
        $entry = $I->entry('news', 'article');

        // The editor clears the field; the next save keeps it clear.
        $entry->setFieldValue('topics', []);
        $I->saveElement($entry);

        $I->assertSame([], Entry::find()->id($entry->id)->one()->getFieldValue('topics')->ids());
    }

    public function anEntryTypeWithoutAPresetStaysEmpty(IntegrationTester $I): void
    {
        $I->preset('news', 'article', ['writers' => [$I->admin()->id]]);

        $entry = $I->entry('news', 'note');

        $I->assertSame([], Entry::find()->id($entry->id)->one()->getFieldValue('writers')->ids());
    }

    public function aFailingPresetNeverStopsTheSave(IntegrationTester $I): void
    {
        $I->preset('news', 'article', ['writers' => [$I->admin()->id]]);
        $break = function() {
            throw new RuntimeException('Broken preset query');
        };
        Event::on(PresetQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, $break);

        $entry = null;
        try {
            $log = $I->primerLogOf(function() use ($I, &$entry) {
                $entry = $I->entry('news', 'article');
            });
        } finally {
            Event::off(PresetQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, $break);
        }

        // One warning with the exception, nothing else.
        $I->assertCount(1, $log);
        $I->assertSame('warning', $log[0]['level']);
        $I->assertStringStartsWith("Primer couldn't apply the preset to a new entry (site default, entry type article): RuntimeException: Broken preset query", $log[0]['message']);
        $I->assertStringContainsString('Stack trace:', $log[0]['message']);
        $saved = Entry::find()->id($entry->id)->one();
        $I->assertNotNull($saved);
        $I->assertSame([], $saved->getFieldValue('writers')->ids());
    }
}
