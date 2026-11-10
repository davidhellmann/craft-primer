<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\GarbageCollection\GarbageCollection;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\User\Models\User;
use DateTime;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Support\Facades\DB;

use function CraftCms\Cms\cp_url;

/**
 * Presets stay consistent when admins change the content model: fields leave the layout, entry types leave their
 * section, sections, entry types and related elements are deleted.
 */
class LifecycleTest extends TestCase
{
    use Content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->solo();
    }

    public function testAChosenFieldThatLeftTheLayoutIsGoneFromThePreset(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $this->removeFromLayout('article', 'writers');

        $read = $this->stored($preset);

        $this->assertSame(['topics'], array_map(fn(CustomField $e) => $e->attribute(), $read->getChosenFieldElements()));
        $this->assertNotContains('Writers', array_column($this->fieldChoiceOptions($read), 'label'));
        $this->assertSame('Topics', (string)$read->getAttributeHtml('fields'));
    }

    public function testAChosenFieldThatLeftTheLayoutIsDroppedFromTheChoiceOnTheNextSave(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $topicsUid = $this->layoutElementUid('article', 'topics');
        $this->removeFromLayout('article', 'writers');

        $read = $this->stored($preset);
        $this->assertCount(2, $read->getFieldUids(), 'Kept until the next save');
        $this->assertTrue(Elements::saveElement($read));

        $this->assertSame([$topicsUid], $this->stored($preset)->getFieldUids());
    }

    public function testAFieldAddedBackToTheLayoutDoesntBringTheOldValueBack(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $oldUid = $this->layoutElementUid('article', 'writers');
        $writers = Fields::getFieldByHandle('writers');
        $this->assertTrue(DB::table(Table::RELATIONS)->where('sourceId', $preset->id)->where('fieldId', $writers->id)->exists());
        $this->removeFromLayout('article', 'writers');
        $this->assertFalse(DB::table(Table::RELATIONS)->where('sourceId', $preset->id)->where('fieldId', $writers->id)->exists(), 'The relations went');
        $this->addToLayout('article', 'writers');
        $newUid = $this->layoutElementUid('article', 'writers');
        $this->assertNotSame($oldUid, $newUid);

        // Chosen again under its new layout element, it starts empty.
        $read = $this->stored($preset);
        $read->setFieldUids([...$read->getFieldUids(), $newUid]);
        $this->assertSame([], $this->ids($read->getFieldValue('writers')));

        $entry = $this->entry('news', 'article');
        $this->assertSame([], $this->ids($entry->getFieldValue('writers')));
    }

    public function testAFieldRemovedAndAddedBackInOneSaveDoesntBringTheOldValueBack(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $oldUid = $this->layoutElementUid('article', 'writers');

        // One save: the field's layout element is replaced by a new one of the same field.
        $type = $this->entryType('article');
        foreach ($type->getFieldLayout()->getTabs() as $tab) {
            $tab->setElements(array_map(
                fn($element) => $element instanceof CustomField && $element->getField()->handle === 'writers'
                    ? new CustomField($element->getField(), ['dateAdded' => new DateTime()])
                    : $element,
                $tab->getElements(),
            ));
        }
        $this->assertTrue(EntryTypes::saveEntryType($type));
        $newUid = $this->layoutElementUid('article', 'writers');
        $this->assertNotSame($oldUid, $newUid);

        $read = $this->stored($preset);
        $read->setFieldUids([$newUid]);
        $this->assertSame([], $this->ids($read->getFieldValue('writers')));
    }

    public function testRemovingOneOfTwoInstancesOfAFieldDoesntCarryItsValueToTheOther(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $type = $this->entryType('article');
        $tab = $type->getFieldLayout()->getTabs()[0];
        $tab->setElements([
            ...$tab->getElements(),
            new CustomField(Fields::getFieldByHandle('writers'), ['handle' => 'coWriters', 'dateAdded' => new DateTime()]),
        ]);
        $this->assertTrue(EntryTypes::saveEntryType($type));
        $coWritersUid = $this->layoutElementUid('article', 'coWriters');

        $this->removeFromLayout('article', 'writers');

        $read = $this->stored($preset);
        $read->setFieldUids([$coWritersUid]);
        $this->assertSame([], $this->ids($read->getFieldValue('coWriters')));
    }

    public function testAChosenFieldThatLeftTheLayoutIsSkippedOnApply(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $this->removeFromLayout('article', 'writers');

        $entry = $this->entry('news', 'article');

        $this->assertSame($this->ids($preset->getFieldValue('topics')), $this->ids($entry->getFieldValue('topics')));
    }

    public function testAnEntryTypeRemovedFromItsSectionKeepsItsPresetHidden(): void
    {
        $preset = $this->presetWithTopicsAndWriters();

        $this->setEntryTypes('news', ['note']);

        $this->assertNotNull(Preset::find()->id($preset->id)->one(), 'The preset stays');
        $this->assertSame([], Preset::find()->inSection()->ids(), 'Primer’s index leaves it out');
        $this->actingAs(User::query()->findOrFail($this->admin()->id));
        $this->get(cp_url("primer/presets/$preset->id"))->assertForbidden();
    }

    public function testAnEntryTypeRemovedFromItsSectionHasAnInertPreset(): void
    {
        $this->presetWithTopicsAndWriters();
        $this->setEntryTypes('news', ['note']);

        // Craft refuses a new entry of a type its section no longer has, unless it saves without validation.
        $entry = new Entry(['sectionId' => $this->section('news')->id, 'typeId' => $this->entryType('article')->id, 'title' => 'Inert']);
        $this->assertTrue(Elements::saveElement($entry, false));

        $this->assertSame([], $this->ids($entry->getFieldValue('topics')));
        $this->assertSame([], $this->ids($entry->getFieldValue('writers')));
    }

    public function testAnEntryTypeAddedBackToItsSectionGetsItsPresetBack(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $this->setEntryTypes('news', ['note']);

        $this->setEntryTypes('news', ['article', 'note']);

        $this->assertSame([$preset->id], Preset::find()->inSection()->ids());
        $entry = $this->entry('news', 'article');
        $this->assertSame($this->ids($preset->getFieldValue('topics')), $this->ids($entry->getFieldValue('topics')));
        $this->assertSame([$this->admin()->id], $this->ids($entry->getFieldValue('writers')));
    }

    public function testDeletingASectionDeletesItsPresets(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $other = $this->preset('topics', 'topic');

        $this->assertTrue(Sections::deleteSection($this->section('news')));
        $this->collectGarbage();

        $this->assertPresetGone($preset->id);
        $this->assertNotNull(Preset::find()->id($other->id)->one(), 'Another section’s preset stays');
    }

    public function testDeletingAnEntryTypeDeletesItsPresets(): void
    {
        $preset = $this->presetWithTopicsAndWriters();
        $note = $this->preset('news', 'note');

        $this->assertTrue(EntryTypes::deleteEntryType($this->entryType('article')));
        $this->collectGarbage();

        $this->assertPresetGone($preset->id);
        $this->assertNotNull(Preset::find()->id($note->id)->one(), 'Another entry type’s preset stays');
    }

    public function testADeletedRelatedElementDisappearsFromThePreset(): void
    {
        [$design, $code] = [$this->topic('Design'), $this->topic('Code')];
        $preset = $this->preset('news', 'article', ['topics' => [$design->id, $code->id]]);

        $this->assertTrue(Elements::deleteElement($design));

        $this->assertSame([$code->id], $this->ids($this->stored($preset)->getFieldValue('topics')));
        $this->assertSame([$code->id], $this->ids($this->entry('news', 'article')->getFieldValue('topics')));

        // Also once Craft deletes it for good.
        $this->collectGarbage();
        $this->assertSame([$code->id], $this->ids($this->stored($preset)->getFieldValue('topics')));
    }

    /**
     * Craft's garbage collection, with everything in the trash old enough to go.
     */
    private function collectGarbage(): void
    {
        $gc = app(GarbageCollection::class);
        $gc->deleteAllTrashed = true;
        $gc->silent = true;
        $gc->run(true);
    }

    private function assertPresetGone(int $id): void
    {
        $this->assertFalse(DB::table(Preset::TABLE)->where('id', $id)->exists(), 'Row');
        $this->assertFalse(DB::table(Table::ELEMENTS)->where('id', $id)->exists(), 'Element');
    }

    /**
     * A preset with Topics and Writers chosen and filled.
     */
    private function presetWithTopicsAndWriters(): Preset
    {
        return $this->preset('news', 'article', ['topics' => [$this->topic('Design')->id], 'writers' => [$this->admin()->id]]);
    }

    /**
     * @return array<array{label: string, value: string}>
     */
    private function fieldChoiceOptions(Preset $preset): array
    {
        return array_map(fn(CustomField $element) => ['label' => (string)$element->label(), 'value' => (string)$element->uid], $preset->getLayoutFieldElements());
    }

    private function removeFromLayout(string $typeHandle, string $fieldHandle): void
    {
        $type = $this->entryType($typeHandle);
        foreach ($type->getFieldLayout()->getTabs() as $tab) {
            $tab->setElements(array_values(array_filter(
                $tab->getElements(),
                fn($element) => !$element instanceof CustomField || $element->getField()->handle !== $fieldHandle,
            )));
        }
        $this->assertTrue(EntryTypes::saveEntryType($type));
        $this->assertNull($this->entryType($typeHandle)->getFieldLayout()->getFieldByHandle($fieldHandle), "$fieldHandle left $typeHandle");
    }

    /**
     * Adds the field back as an admin does: a new layout element, with a new UID.
     */
    private function addToLayout(string $typeHandle, string $fieldHandle): void
    {
        $type = $this->entryType($typeHandle);
        $tab = $type->getFieldLayout()->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField(Fields::getFieldByHandle($fieldHandle))]);
        $this->assertTrue(EntryTypes::saveEntryType($type));
    }

    /**
     * @param string[] $typeHandles
     */
    private function setEntryTypes(string $sectionHandle, array $typeHandles): void
    {
        $section = $this->section($sectionHandle);
        $section->setEntryTypes(array_map(fn(string $handle) => $this->entryType($handle), $typeHandles));
        $this->assertTrue(Sections::saveSection($section));
        Sections::refreshSections();
    }
}
