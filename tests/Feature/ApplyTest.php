<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Entries;
use CraftCms\Cms\Support\Facades\Fields;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\services\Applier;
use davidhellmann\primer\services\Presets;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A new entry starts with its preset's elements in the chosen fields it leaves empty.
 */
class ApplyTest extends TestCase
{
    use Content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->solo();
    }

    public function testANewEntryGetsThePresetsElements(): void
    {
        [$design, $code] = [$this->topic('Design'), $this->topic('Code')];
        $this->preset('news', 'article', [
            'topics' => [$code->id, $design->id],
            'writers' => [$this->admin()->id],
        ]);

        $entry = $this->entry('news', 'article', ['title' => 'Hello']);

        $saved = Entry::find()->id($entry->id)->one();
        $this->assertSame([$code->id, $design->id], $this->ids($saved->getFieldValue('topics')));
        $this->assertSame([$this->admin()->id], $this->ids($saved->getFieldValue('writers')));
        $this->assertSame([], $this->ids($saved->getFieldValue('relatedArticles')));
    }

    public function testAFieldTheEntryAlreadyFillsStaysUntouched(): void
    {
        [$design, $code, $craft] = [$this->topic('Design'), $this->topic('Code'), $this->topic('Craft')];
        $this->preset('news', 'article', ['topics' => [$code->id, $design->id], 'writers' => [$this->admin()->id]]);

        $entry = $this->entry('news', 'article', ['topics' => [$craft->id]]);

        $saved = Entry::find()->id($entry->id)->one();
        $this->assertSame([$craft->id], $this->ids($saved->getFieldValue('topics')));
        // The other chosen field was empty, so it still gets the preset's.
        $this->assertSame([$this->admin()->id], $this->ids($saved->getFieldValue('writers')));
    }

    public function testAFieldTakesNoMoreThanItsMaximum(): void
    {
        $topics = [$this->topic('Design'), $this->topic('Code'), $this->topic('Craft')];
        $this->preset('news', 'article', ['topics' => array_map(fn(Entry $topic) => $topic->id, $topics)]);
        // The field allows fewer elements now than the preset holds.
        $field = Fields::getFieldByHandle('topics');
        $this->assertInstanceOf(Entries::class, $field);
        $field->maxRelations = 2;
        $this->assertTrue(Fields::saveField($field));

        $entry = $this->entry('news', 'article');

        $saved = Entry::find()->id($entry->id)->one();
        $this->assertSame([$topics[0]->id, $topics[1]->id], $this->ids($saved->getFieldValue('topics')->limit(null)));
    }

    public function testAnAssetsFieldIsFilledToo(): void
    {
        $image = $this->image('hero.png');
        $this->preset('news', 'article', ['heroImage' => [$image->id]]);

        $entry = $this->entry('news', 'article');

        $this->assertSame([$image->id], $this->ids(Entry::find()->id($entry->id)->one()->getFieldValue('heroImage')));
    }

    public function testAnExistingEntryIsNotFilledAgain(): void
    {
        $topic = $this->topic('Design');
        $this->preset('news', 'article', ['topics' => [$topic->id]]);
        $entry = $this->entry('news', 'article');

        // The editor clears the field; the next save keeps it clear.
        $entry->setFieldValue('topics', []);
        $this->assertTrue(\CraftCms\Cms\Support\Facades\Elements::saveElement($entry));

        $this->assertSame([], $this->ids(Entry::find()->id($entry->id)->one()->getFieldValue('topics')));
    }

    public function testAnEntryTypeWithoutAPresetStaysEmpty(): void
    {
        $this->preset('news', 'article', ['writers' => [$this->admin()->id]]);

        $entry = $this->entry('news', 'note');

        $this->assertSame([], $this->ids(Entry::find()->id($entry->id)->one()->getFieldValue('writers')));
    }

    public function testAFailingPresetNeverStopsTheSave(): void
    {
        $this->preset('news', 'article', ['writers' => [$this->admin()->id]]);
        $this->app->instance(Presets::class, new class () extends Presets {
            public function inSite(int $sectionId, int $typeId, int $siteId): ?Preset
            {
                throw new RuntimeException('Broken preset query');
            }
        });
        $this->app->forgetInstance(Applier::class);
        $log = Log::spy();

        $entry = $this->entry('news', 'article');

        // One warning with the exception, nothing else.
        $log->shouldHaveReceived('warning')->once()->withArgs(fn(string $message) => str_starts_with($message, "Primer couldn't apply the preset to a new entry (site default, entry type article): RuntimeException: Broken preset query")
            && str_contains($message, 'Stack trace:'));
        $log->shouldNotHaveReceived('info', [\Mockery::pattern('/^Primer/')]);
        $saved = Entry::find()->id($entry->id)->one();
        $this->assertNotNull($saved);
        $this->assertSame([], $this->ids($saved->getFieldValue('writers')));
    }
}
