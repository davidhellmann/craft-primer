<?php

namespace davidhellmann\primer\tests\integration;

use Craft;
use craft\console\Request as ConsoleRequest;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\events\MultiElementActionEvent;
use craft\services\Elements;
use davidhellmann\primer\elements\db\PresetQuery;
use davidhellmann\primer\tests\Support\IntegrationTester;
use yii\base\Event;

/**
 * The preset applies once, whichever way an entry comes into being or changes entry type,
 * and never in saves that only copy or re-save.
 */
class SaveFlowsCest
{
    public function anEntrySwitchedToAnotherEntryTypeGetsThatTypesPreset(IntegrationTester $I): void
    {
        $I->preset('news', 'note', ['writers' => [$I->admin()->id]]);
        $entry = $I->entry('news', 'article');

        $switched = Entry::find()->id($entry->id)->one();
        $switched->setTypeId($I->entryType('note')->id);
        $I->saveElement($switched);

        $I->assertSame([$I->admin()->id], Entry::find()->id($entry->id)->one()->getFieldValue('writers')->ids());
    }

    public function settingTheSameEntryTypeAgainIsNoSwitch(IntegrationTester $I): void
    {
        $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id]]);
        $entry = $I->entry('news', 'article');
        $entry->setFieldValue('topics', []);
        $I->saveElement($entry);

        // The editor posts the entry type with every save.
        $saved = Entry::find()->id($entry->id)->one();
        $saved->setTypeId($I->entryType('article')->id);
        $I->saveElement($saved);

        $I->assertSame([], Entry::find()->id($entry->id)->one()->getFieldValue('topics')->ids());
    }

    public function aNewEntryInTheControlPanelShowsThePresetWhenTheEditorOpens(IntegrationTester $I): void
    {
        $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id]]);
        $I->amLoggedInAs($I->admin());

        // Craft saves the new entry as an unpublished draft, then opens the editor on it.
        $I->amOnPage('/admin/entries/news/new');

        $I->seeResponseCodeIs(200);
        $I->see('Design', '[data-attribute="topics"]');
    }

    public function publishingANewEntrysDraftDoesNotApplyAgain(IntegrationTester $I): void
    {
        $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id]]);
        $I->amLoggedInAs($I->admin());
        $I->amOnPage('/admin/entries/news/new');
        $draft = Entry::find()->drafts()->section('news')->status(null)->one();
        $I->assertNotNull($draft);

        // The editor adds a title and clears the field, the draft autosaves, then the editor publishes it.
        $draft->setTypeId($draft->getTypeId());
        $draft->title = 'Hello';
        $draft->setFieldValue('topics', []);
        $I->saveElement($draft);
        $entry = Craft::$app->getDrafts()->applyDraft($draft);

        $published = Entry::find()->id($entry->id)->one();
        $I->assertNotNull($published);
        $I->assertSame([], $published->getFieldValue('topics')->ids());
    }

    public function switchingTheEntryTypeInTheEditorShowsThatTypesPresetAndPublishesIt(IntegrationTester $I): void
    {
        $I->preset('news', 'note', ['writers' => [$I->admin()->id]]);
        $entry = $I->entry('news', 'article', ['title' => 'Hello']);
        $I->amLoggedInAs($I->admin());

        $I->haveHttpHeader('Accept', 'application/json');
        // With autosave on, the editor saves a provisional draft with the new type and renders its layout.
        $I->sendAjaxPostRequest('/admin/actions/elements/save-draft', [
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'provisional' => 1,
            'typeId' => $I->entryType('note')->id,
        ]);

        $I->seeResponseCodeIs(200);
        $draft = Entry::find()->provisionalDrafts()->draftOf($entry)->status(null)->one();
        $I->assertNotNull($draft);
        $I->assertSame([$I->admin()->id], $draft->getFieldValue('writers')->ids());

        Craft::$app->getDrafts()->applyDraft($draft);

        $published = Entry::find()->id($entry->id)->one();
        $I->assertSame('note', $published->getType()->handle);
        $I->assertSame([$I->admin()->id], $published->getFieldValue('writers')->ids());
    }

    public function aDuplicateCopiesTheEntryWithoutApplying(IntegrationTester $I): void
    {
        $entry = $this->entryWithItsPresetCleared($I);

        $duplicate = Craft::$app->getElements()->duplicateElement($entry);

        $I->assertSame([], Entry::find()->id($duplicate->id)->one()->getFieldValue('topics')->ids());
    }

    public function savingAsANewEntryCopiesTheEntryWithoutApplying(IntegrationTester $I): void
    {
        $entry = $this->entryWithItsPresetCleared($I);

        // "Save as a new entry" in the editor: a duplicate as an unpublished draft.
        $draft = Craft::$app->getElements()->duplicateElement($entry, asUnpublishedDraft: true);

        $saved = Entry::find()->id($draft->id)->drafts()->status(null)->one();
        $I->assertNotNull($saved);
        $I->assertSame([], $saved->getFieldValue('topics')->ids());
    }

    public function anEntryCreatedFromTheConsoleIsFilled(IntegrationTester $I): void
    {
        $topic = $I->topic('Design');
        $I->preset('news', 'article', ['topics' => [$topic->id]]);

        // An import (Feed Me's queue job), a console command or a migration: a plain save, no draft, no CP request.
        $request = Craft::$app->getRequest();
        Craft::$app->set('request', new ConsoleRequest());
        try {
            $I->assertTrue(Craft::$app->getRequest()->getIsConsoleRequest());
            $entry = new Entry([
                'sectionId' => $I->section('news')->id,
                'typeId' => $I->entryType('article')->id,
                'title' => 'Imported',
            ]);
            $I->assertTrue(Craft::$app->getElements()->saveElement($entry));
        } finally {
            Craft::$app->set('request', $request);
        }

        $I->assertSame([$topic->id], Entry::find()->id($entry->id)->one()->getFieldValue('topics')->ids());
    }

    public function aResaveFromTheConsoleDoesNotApplyEvenWhenItSwitchesTheEntryType(IntegrationTester $I): void
    {
        $I->preset('news', 'note', ['writers' => [$I->admin()->id]]);
        $entry = $I->entry('news', 'article');
        $noteId = $I->entryType('note')->id;

        // What `craft resave/entries --set typeId --to <id>` does to each entry before saving it.
        $switch = function(MultiElementActionEvent $event) use ($noteId) {
            /** @var Entry $element */
            $element = $event->element;
            $element->typeId = $noteId;
        };
        Event::on(Elements::class, Elements::EVENT_BEFORE_RESAVE_ELEMENT, $switch);
        try {
            Craft::$app->getElements()->resaveElements(Entry::find()->id($entry->id));
        } finally {
            Event::off(Elements::class, Elements::EVENT_BEFORE_RESAVE_ELEMENT, $switch);
        }

        $resaved = Entry::find()->id($entry->id)->one();
        $I->assertSame('note', $resaved->getType()->handle);
        $I->assertSame([], $resaved->getFieldValue('writers')->ids());
    }

    public function eachFilledEntryLogsOneInfoLine(IntegrationTester $I): void
    {
        $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id], 'writers' => [$I->admin()->id]]);

        $entry = null;
        $log = $I->primerLogOf(function() use ($I, &$entry) {
            $entry = $I->entry('news', 'article');
            // A note has no preset, so it gets nothing and logs nothing.
            $I->entry('news', 'note');
        });

        $I->assertSame([[
            'level' => 'info',
            'message' => "Primer filled topics, writers in entry $entry->id (site default, entry type article).",
        ]], $log);
    }

    public function thePresetLoadsOncePerSectionEntryTypeAndSite(IntegrationTester $I): void
    {
        $topic = $I->topic('Design');
        $I->preset('news', 'article', ['topics' => [$topic->id]]);

        $queries = 0;
        $count = function() use (&$queries) {
            $queries++;
        };
        Event::on(PresetQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, $count);
        try {
            $articles = [$I->entry('news', 'article'), $I->entry('news', 'article')];
            $I->entry('news', 'note');
            $I->entry('news', 'note');
        } finally {
            Event::off(PresetQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, $count);
        }

        // One for the articles, one for the notes, which have no preset.
        $I->assertSame(2, $queries);
        foreach ($articles as $article) {
            $I->assertSame([$topic->id], Entry::find()->id($article->id)->one()->getFieldValue('topics')->ids());
        }
    }

    /**
     * A saved article that got its preset's topic, then had the field cleared by the editor.
     */
    private function entryWithItsPresetCleared(IntegrationTester $I): Entry
    {
        $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id]]);
        $entry = $I->entry('news', 'article', ['title' => 'Hello']);
        $entry->setFieldValue('topics', []);
        $I->saveElement($entry);

        return Entry::find()->id($entry->id)->one();
    }
}
