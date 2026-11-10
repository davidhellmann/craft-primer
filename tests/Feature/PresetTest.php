<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\User\Models\User;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\fieldlayoutelements\EntryTypeField;
use davidhellmann\primer\fieldlayoutelements\FieldChoiceField;
use davidhellmann\primer\fieldlayoutelements\SectionField;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Support\Facades\DB;

use function CraftCms\Cms\cp_url;

/**
 * The Preset element: stored by Craft like any element, its values in Craft's own content and relations.
 */
class PresetTest extends TestCase
{
    use Content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->solo();
    }

    public function testAPresetIsSavedAndReadBack(): void
    {
        [$design, $code] = [$this->topic('Design'), $this->topic('Code')];
        $topicsUid = $this->layoutElementUid('article', 'topics');

        $preset = new Preset([
            'sectionId' => $this->section('news')->id,
            'typeId' => $this->entryType('article')->id,
        ]);
        $preset->setFieldUids([$topicsUid]);
        $preset->setFieldValue('topics', [$code->id, $design->id]);
        $this->assertTrue(Elements::saveElement($preset));

        $read = Preset::find()->id($preset->id)->one();
        $this->assertInstanceOf(Preset::class, $read);
        $this->assertSame($this->section('news')->id, $read->sectionId);
        $this->assertSame($this->entryType('article')->id, $read->typeId);
        $this->assertSame([$topicsUid], $read->getFieldUids());
        $this->assertSame([$code->id, $design->id], $this->ids($read->getFieldValue('topics')));
    }

    public function testItHoldsTheEntryTypesRelationFieldsUnderTheSameUids(): void
    {
        $type = $this->entryType('article');
        $layout = $type->getFieldLayout();
        $layout->getField(fn($field) => $field instanceof CustomField && $field->attribute() === 'topics')->required(true);
        $this->assertTrue(EntryTypes::saveEntryType($type));
        $preset = new Preset(['sectionId' => $this->section('news')->id, 'typeId' => $this->entryType('article')->id]);

        $elements = $preset->getLayoutFieldElements();

        // Summary (plain text) is left out; the Entries, Assets and Users fields come in layout order.
        $this->assertSame(['topics', 'heroImage', 'writers', 'relatedArticles'], array_map(fn(CustomField $e) => $e->attribute(), $elements));
        foreach ($elements as $element) {
            $this->assertSame($this->layoutElementUid('article', $element->attribute()), $element->uid);
            // Topics is required in Article; a preset doesn't need it.
            $this->assertFalse($element->required);
            $this->assertNull($element->getUserCondition());
            $this->assertNull($element->getElementCondition());
        }
    }

    public function testItsFieldLayoutIsTheFieldChoiceAndTheChosenFields(): void
    {
        $preset = $this->preset('news', 'article', ['writers' => [], 'topics' => []]);

        $elements = $this->stored($preset)->getFieldLayout()->getTabs()[0]->getElements();

        $this->assertInstanceOf(FieldChoiceField::class, $elements[0]);
        // In layout order, whatever order they were chosen in.
        $this->assertSame(['topics', 'writers'], array_map(
            fn($e) => $e instanceof CustomField ? $e->attribute() : $e::class,
            array_slice($elements, 1),
        ));
        $this->assertSame(['topics', 'writers'], array_map(fn(CustomField $e) => $e->attribute(), $preset->getChosenFieldElements()));
    }

    public function testANewPresetsFieldLayoutStartsWithItsSectionAndEntryType(): void
    {
        $preset = new Preset(['sectionId' => $this->section('news')->id, 'typeId' => $this->entryType('article')->id]);

        $elements = $preset->getFieldLayout()->getTabs()[0]->getElements();

        $this->assertSame(
            [SectionField::class, EntryTypeField::class, FieldChoiceField::class],
            array_map(fn($element) => $element::class, $elements),
        );
    }

    public function testAPresetNeedsASectionAndAnEntryType(): void
    {
        $preset = new Preset();

        $this->assertFalse(Elements::saveElement($preset));
        $this->assertTrue($preset->errors()->has('sectionId'));
        $this->assertTrue($preset->errors()->has('typeId'));
    }

    public function testTheEntryTypeMustBelongToTheSection(): void
    {
        $preset = new Preset(['sectionId' => $this->section('news')->id, 'typeId' => $this->entryType('topic')->id]);

        $this->assertFalse(Elements::saveElement($preset));
        $this->assertTrue($preset->errors()->has('typeId'));
    }

    public function testAnEntryTypeHasOnePresetPerSectionAndSite(): void
    {
        $this->preset('news', 'article');
        $second = new Preset(['sectionId' => $this->section('news')->id, 'typeId' => $this->entryType('article')->id]);

        $this->assertFalse(Elements::saveElement($second));
        $this->assertTrue($second->errors()->has('typeId'));

        // Another entry type of the same section is fine.
        $this->preset('news', 'note');
    }

    public function testAChosenFieldKeepsItsMaximumNumberOfRelations(): void
    {
        $articles = [$this->entry('news', 'note'), $this->entry('news', 'note'), $this->entry('news', 'note')];
        $preset = new Preset(['sectionId' => $this->section('news')->id, 'typeId' => $this->entryType('article')->id]);
        $preset->setFieldUids([$this->layoutElementUid('article', 'relatedArticles')]);
        // Related articles allows two.
        $preset->setFieldValue('relatedArticles', array_map(fn($entry) => $entry->id, $articles));

        $this->assertFalse(Elements::saveElement($preset));
        $this->assertTrue($preset->errors()->has('relatedArticles'));

        $preset->setFieldValue('relatedArticles', [$articles[0]->id, $articles[1]->id]);
        $this->assertTrue(Elements::saveElement($preset));
    }

    public function testItsRowGoesWithItsSectionOrEntryType(): void
    {
        $article = $this->preset('news', 'article');
        $this->preset('news', 'note');
        $this->preset('topics', 'topic');

        // Craft soft-deletes sections and entry types and removes their rows later; the foreign keys take the
        // preset rows along then, and garbage collection the elements.
        DB::table(Table::ENTRYTYPES)->where('id', $this->entryType('note')->id)->delete();
        DB::table(Table::SECTIONS)->where('id', $this->section('topics')->id)->delete();

        $this->assertSame([$article->id], DB::table(Preset::TABLE)->pluck('id')->map(fn($id) => (int)$id)->all());
    }

    public function testPresetsAreFoundBySectionAndEntryType(): void
    {
        $article = $this->preset('news', 'article');
        $note = $this->preset('news', 'note');

        $this->assertSame([$article->id], Preset::find()->sectionId($this->section('news')->id)->typeId($this->entryType('article')->id)->ids());
        $this->assertEqualsCanonicalizing([$article->id, $note->id], Preset::find()->sectionId($this->section('news')->id)->ids());
        $this->assertSame([], Preset::find()->sectionId($this->section('topics')->id)->ids());
    }

    public function testASavedPresetKeepsItsSectionAndEntryType(): void
    {
        $preset = $this->preset('news', 'article');

        $preset->typeId = $this->entryType('note')->id;
        $this->assertFalse(Elements::saveElement($preset));
        $this->assertTrue($preset->errors()->has('typeId'));

        $preset->typeId = $this->entryType('article')->id;
        $preset->sectionId = $this->section('topics')->id;
        $this->assertFalse(Elements::saveElement($preset));
        $this->assertTrue($preset->errors()->has('sectionId'));
    }

    public function testCraftsOwnSaveActionDoesntMoveAPreset(): void
    {
        $preset = $this->preset('news', 'article');
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        $this->postJson(cp_url('actions/elements/save'), [
            'elementType' => Preset::class,
            'elementId' => $preset->id,
            'siteId' => $preset->siteId,
            'sectionId' => $this->section('topics')->id,
            'typeId' => $this->entryType('note')->id,
        ]);

        $stored = Preset::find()->id($preset->id)->one();
        $this->assertSame($this->section('news')->id, $stored->sectionId);
        $this->assertSame($this->entryType('article')->id, $stored->typeId);
    }

    public function testADeletedPresetIsGoneForGood(): void
    {
        $preset = $this->preset('news', 'article');

        // Presets have no trash: Craft's default soft delete deletes them for good too.
        Elements::deleteElement($preset);

        $this->assertFalse(DB::table(Table::ELEMENTS)->where('id', $preset->id)->exists());
        $this->assertFalse(DB::table(Preset::TABLE)->where('id', $preset->id)->exists());
    }
}
