<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Element\Events\ElementResaving;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\User\Models\User;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

use function CraftCms\Cms\cp_url;

/**
 * The preset applies once, whichever way an entry comes into being or changes entry type, and never in saves that
 * only copy or re-save.
 */
class SaveFlowsTest extends TestCase
{
    use Content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->solo();
    }

    public function testAnEntrySwitchedToAnotherEntryTypeGetsThatTypesPreset(): void
    {
        $this->preset('news', 'note', ['writers' => [$this->admin()->id]]);
        $entry = $this->entry('news', 'article');

        $switched = Entry::find()->id($entry->id)->one();
        $switched->setTypeId($this->entryType('note')->id);
        $this->assertTrue(Elements::saveElement($switched));

        $this->assertSame([$this->admin()->id], $this->ids(Entry::find()->id($entry->id)->one()->getFieldValue('writers')));
    }

    public function testSettingTheSameEntryTypeAgainIsNoSwitch(): void
    {
        $this->preset('news', 'article', ['topics' => [$this->topic('Design')->id]]);
        $entry = $this->entry('news', 'article');
        $entry->setFieldValue('topics', []);
        $this->assertTrue(Elements::saveElement($entry));

        // The editor posts the entry type with every save.
        $saved = Entry::find()->id($entry->id)->one();
        $saved->setTypeId($this->entryType('article')->id);
        $this->assertTrue(Elements::saveElement($saved));

        $this->assertSame([], $this->ids(Entry::find()->id($entry->id)->one()->getFieldValue('topics')));
    }

    public function testANewEntryInTheControlPanelHasThePresetWhenTheEditorOpens(): void
    {
        $topic = $this->topic('Design');
        $this->preset('news', 'article', ['topics' => [$topic->id]]);
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        // Craft saves the new entry as an unpublished draft, then opens the editor on it.
        $this->get(cp_url('entries/news/new'))->assertRedirect();

        $draft = Entry::find()->drafts()->section('news')->status(null)->one();
        $this->assertInstanceOf(Entry::class, $draft);
        $this->assertSame([$topic->id], $this->ids($draft->getFieldValue('topics')));
    }

    public function testPublishingANewEntrysDraftDoesNotApplyAgain(): void
    {
        $this->preset('news', 'article', ['topics' => [$this->topic('Design')->id]]);
        $this->actingAs(User::query()->findOrFail($this->admin()->id));
        $this->get(cp_url('entries/news/new'));
        $draft = Entry::find()->drafts()->section('news')->status(null)->one();
        $this->assertInstanceOf(Entry::class, $draft);

        // The editor adds a title and clears the field, the draft is saved, then the editor publishes it.
        $draft->setTypeId($draft->getTypeId());
        $draft->title = 'Hello';
        $draft->setFieldValue('topics', []);
        $this->assertTrue(Elements::saveElement($draft));
        $entry = Drafts::applyDraft($draft);

        $published = Entry::find()->id($entry->id)->one();
        $this->assertNotNull($published);
        $this->assertSame([], $this->ids($published->getFieldValue('topics')));
    }

    public function testSwitchingTheEntryTypeInTheEditorShowsThatTypesPresetAndPublishesIt(): void
    {
        $this->preset('news', 'note', ['writers' => [$this->admin()->id]]);
        $entry = $this->entry('news', 'article', ['title' => 'Hello']);
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        // With autosave on, the editor saves a provisional draft with the new type and renders its layout.
        $this->postJson(cp_url('actions/elements/save-draft'), [
            'elementType' => Entry::class,
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'provisional' => 1,
            'typeId' => $this->entryType('note')->id,
        ])->assertOk();

        $draft = Entry::find()->provisionalDrafts()->draftOf($entry)->status(null)->one();
        $this->assertInstanceOf(Entry::class, $draft);
        $this->assertSame([$this->admin()->id], $this->ids($draft->getFieldValue('writers')));

        Drafts::applyDraft($draft);

        $published = Entry::find()->id($entry->id)->one();
        $this->assertSame('note', $published->getType()->handle);
        $this->assertSame([$this->admin()->id], $this->ids($published->getFieldValue('writers')));
    }

    public function testADuplicateCopiesTheEntryWithoutApplying(): void
    {
        $entry = $this->entryWithItsPresetCleared();

        $duplicate = Elements::duplicateElement($entry);

        $this->assertSame([], $this->ids(Entry::find()->id($duplicate->id)->one()->getFieldValue('topics')));
    }

    public function testSavingAsANewEntryCopiesTheEntryWithoutApplying(): void
    {
        $entry = $this->entryWithItsPresetCleared();

        // "Save as a new entry" in the editor: a duplicate as an unpublished draft.
        $draft = Elements::duplicateElement($entry, asUnpublishedDraft: true);

        $saved = Entry::find()->id($draft->id)->drafts()->status(null)->one();
        $this->assertNotNull($saved);
        $this->assertSame([], $this->ids($saved->getFieldValue('topics')));
    }

    public function testAnEntryCreatedOutsideTheControlPanelIsFilled(): void
    {
        $topic = $this->topic('Design');
        $this->preset('news', 'article', ['topics' => [$topic->id]]);

        // An import, a console command or a migration: a plain save, no draft, no control panel request.
        $entry = new Entry([
            'sectionId' => $this->section('news')->id,
            'typeId' => $this->entryType('article')->id,
            'title' => 'Imported',
        ]);
        $this->assertTrue(Elements::saveElement($entry));

        $this->assertSame([$topic->id], $this->ids(Entry::find()->id($entry->id)->one()->getFieldValue('topics')));
    }

    public function testAResaveDoesNotApplyEvenWhenItSwitchesTheEntryType(): void
    {
        $this->preset('news', 'note', ['writers' => [$this->admin()->id]]);
        $entry = $this->entry('news', 'article');
        $noteId = $this->entryType('note')->id;

        // What `php artisan craft:resave:entries --set typeId --to <id>` does to each entry before saving it.
        Event::listen(function(ElementResaving $event) use ($noteId) {
            if ($event->element instanceof Entry) {
                $event->element->setTypeId($noteId);
            }
        });
        Elements::resaveElements(Entry::find()->id($entry->id));

        $resaved = Entry::find()->id($entry->id)->one();
        $this->assertSame('note', $resaved->getType()->handle);
        $this->assertSame([], $this->ids($resaved->getFieldValue('writers')));
    }

    public function testEachFilledEntryLogsOneInfoLine(): void
    {
        $this->preset('news', 'article', ['topics' => [$this->topic('Design')->id], 'writers' => [$this->admin()->id]]);
        $log = Log::spy();

        $entry = $this->entry('news', 'article');
        // A note has no preset, so it gets nothing and logs nothing.
        $this->entry('news', 'note');

        $log->shouldHaveReceived('info')->once()->with("Primer filled topics, writers in entry $entry->id (site default, entry type article).");
        $log->shouldNotHaveReceived('warning');
    }

    public function testThePresetLoadsOncePerSectionEntryTypeAndSite(): void
    {
        $topic = $this->topic('Design');
        $this->preset('news', 'article', ['topics' => [$topic->id]]);

        $queries = 0;
        DB::listen(function(QueryExecuted $query) use (&$queries) {
            if (preg_match('/^select .* from "?' . Preset::TABLE . '"?/i', $query->sql)) {
                $queries++;
            }
        });
        $articles = [$this->entry('news', 'article'), $this->entry('news', 'article')];
        $this->entry('news', 'note');
        $this->entry('news', 'note');

        // One for the articles, one for the notes, which have no preset.
        $this->assertSame(2, $queries);
        foreach ($articles as $article) {
            $this->assertSame([$topic->id], $this->ids(Entry::find()->id($article->id)->one()->getFieldValue('topics')));
        }
    }

    /**
     * A saved article that got its preset's topic, then had the field cleared by the editor.
     */
    private function entryWithItsPresetCleared(): Entry
    {
        $this->preset('news', 'article', ['topics' => [$this->topic('Design')->id]]);
        $entry = $this->entry('news', 'article', ['title' => 'Hello']);
        $entry->setFieldValue('topics', []);
        $this->assertTrue(Elements::saveElement($entry));

        return Entry::find()->id($entry->id)->one();
    }
}
