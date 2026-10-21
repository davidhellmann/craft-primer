<?php

namespace davidhellmann\primer\tests\integration;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayoutTab;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\Plugin;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * Presets stay consistent when admins change the content model (spec 4.4, 4.7): fields leave the layout, entry types
 * leave their section, sections, entry types and related elements are deleted.
 */
class LifecycleCest
{
    public function _before(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
    }

    public function aChosenFieldThatLeftTheLayoutIsGoneFromTheForm(IntegrationTester $I): void
    {
        $preset = $this->presetWithTopicsAndWriters($I);
        $writersUid = $I->layoutElementUid('article', 'writers');
        $this->removeFromLayout($I, 'article', 'writers');

        $I->amOnPage("/admin/primer/presets/$preset->id");

        $I->seeResponseCodeIs(200);
        $I->seeElement('[data-primer-field="' . $I->layoutElementUid('article', 'topics') . '"]:not(.hidden)');
        $I->dontSeeElement("[data-primer-field=\"$writersUid\"]");
        $I->dontSeeElement("input[name=\"fieldUids[]\"][value=\"$writersUid\"]");
        $I->dontSee('Writers', '[data-primer-add-menu] .menu-item');
    }

    public function aChosenFieldThatLeftTheLayoutIsNotCounted(IntegrationTester $I): void
    {
        $this->presetWithTopicsAndWriters($I);
        $this->removeFromLayout($I, 'article', 'writers');

        $I->amOnPage('/admin/primer');
        $I->see('1 field', '[data-section="news"] [data-preset]');

        $I->amOnPage('/admin/primer/sections/news');
        $I->see('Topics', '[data-primer-type="article"] [data-primer-fields]');
        $I->dontSee('Writers', '[data-primer-type="article"] [data-primer-fields]');
    }

    public function aChosenFieldThatLeftTheLayoutIsDroppedFromTheChoiceOnTheNextSave(IntegrationTester $I): void
    {
        $preset = $this->presetWithTopicsAndWriters($I);
        $topicsUid = $I->layoutElementUid('article', 'topics');
        $this->removeFromLayout($I, 'article', 'writers');

        $read = Preset::find()->id($preset->id)->one();
        $I->assertCount(2, $read->getFieldUids(), 'Kept until the next save');
        $I->assertTrue(Plugin::getInstance()->presets->save($read));

        $I->assertSame([$topicsUid], Preset::find()->id($preset->id)->one()->getFieldUids());
    }

    public function aFieldAddedBackToTheLayoutDoesntBringTheOldValueBack(IntegrationTester $I): void
    {
        $preset = $this->presetWithTopicsAndWriters($I);
        $oldUid = $I->layoutElementUid('article', 'writers');
        $this->removeFromLayout($I, 'article', 'writers');
        $this->addToLayout($I, 'article', 'writers');
        $newUid = $I->layoutElementUid('article', 'writers');
        $I->assertNotSame($oldUid, $newUid);

        $read = Preset::find()->id($preset->id)->one();
        $I->assertSame([$I->layoutElementUid('article', 'topics')], array_map(fn(CustomField $e) => $e->uid, $read->getChosenFieldElements()));
        $I->assertSame([], $read->getFieldValue('writers')->ids());

        $entry = $I->entry('news', 'article');
        $I->assertSame([], $entry->getFieldValue('writers')->ids());
    }

    public function aChosenFieldThatLeftTheLayoutIsSkippedOnApply(IntegrationTester $I): void
    {
        $preset = $this->presetWithTopicsAndWriters($I);
        $this->removeFromLayout($I, 'article', 'writers');

        $entry = $I->entry('news', 'article');

        $I->assertSame($preset->getFieldValue('topics')->ids(), $entry->getFieldValue('topics')->ids());
    }

    public function anEntryTypeRemovedFromItsSectionKeepsItsPresetHidden(IntegrationTester $I): void
    {
        $preset = $this->presetWithTopicsAndWriters($I);

        $this->setEntryTypes($I, 'news', ['note']);

        $I->assertNotNull(Preset::find()->id($preset->id)->one(), 'The preset stays');
        $I->amOnPage('/admin/primer');
        $I->dontSeeElement('[data-section="news"] [data-preset]');
        $I->dontSee('Article', '[data-section="news"]');
        $I->amOnPage('/admin/primer/sections/news');
        $I->dontSeeElement('[data-primer-type="article"]');
        $I->amOnPage("/admin/primer/presets/$preset->id");
        $I->seeResponseCodeIs(404);
    }

    public function anEntryTypeRemovedFromItsSectionHasAnInertPreset(IntegrationTester $I): void
    {
        $this->presetWithTopicsAndWriters($I);
        $this->setEntryTypes($I, 'news', ['note']);

        // Craft refuses a new entry of a type its section no longer has, unless it saves without validation.
        $entry = new Entry(['sectionId' => $I->section('news')->id, 'typeId' => $I->entryType('article')->id]);
        $I->assertFalse(Craft::$app->getElements()->saveElement($entry));
        $I->assertTrue(Craft::$app->getElements()->saveElement($entry, false));

        $I->assertSame([], $entry->getFieldValue('topics')->ids());
        $I->assertSame([], $entry->getFieldValue('writers')->ids());
    }

    public function anEntryTypeAddedBackToItsSectionGetsItsPresetBack(IntegrationTester $I): void
    {
        $preset = $this->presetWithTopicsAndWriters($I);
        $this->setEntryTypes($I, 'news', ['note']);

        $this->setEntryTypes($I, 'news', ['article', 'note']);

        $I->amOnPage('/admin/primer');
        $I->see('2 fields', '[data-section="news"] [data-preset]');
        $entry = $I->entry('news', 'article');
        $I->assertSame($preset->getFieldValue('topics')->ids(), $entry->getFieldValue('topics')->ids());
        $I->assertSame([$I->admin()->id], $entry->getFieldValue('writers')->ids());
    }

    public function deletingASectionDeletesItsPresets(IntegrationTester $I): void
    {
        $preset = $this->presetWithTopicsAndWriters($I);
        $other = $I->preset('topics', 'topic');

        $I->assertTrue(Craft::$app->getEntries()->deleteSection($I->section('news')));
        $this->collectGarbage();

        $this->seePresetGone($I, $preset->id);
        $I->assertNotNull(Preset::find()->id($other->id)->one(), 'Another section’s preset stays');
    }

    public function deletingAnEntryTypeDeletesItsPresets(IntegrationTester $I): void
    {
        $preset = $this->presetWithTopicsAndWriters($I);
        $note = $I->preset('news', 'note');

        $I->assertTrue(Craft::$app->getEntries()->deleteEntryType($I->entryType('article')));
        $this->collectGarbage();

        $this->seePresetGone($I, $preset->id);
        $I->assertNotNull(Preset::find()->id($note->id)->one(), 'Another entry type’s preset stays');
    }

    public function aDeletedRelatedElementDisappearsFromThePreset(IntegrationTester $I): void
    {
        [$design, $code] = [$I->topic('Design'), $I->topic('Code')];
        $preset = $I->preset('news', 'article', ['topics' => [$design->id, $code->id]]);

        $I->assertTrue(Craft::$app->getElements()->deleteElement($design));

        $I->assertSame([$code->id], Preset::find()->id($preset->id)->one()->getFieldValue('topics')->ids());
        $I->assertSame([$code->id], $I->entry('news', 'article')->getFieldValue('topics')->ids());

        // Also once Craft deletes it for good.
        $this->collectGarbage();
        $I->assertSame([$code->id], Preset::find()->id($preset->id)->one()->getFieldValue('topics')->ids());
    }

    /**
     * Craft's garbage collection, with everything in the trash old enough to go.
     */
    private function collectGarbage(): void
    {
        $gc = Craft::$app->getGc();
        $gc->deleteAllTrashed = true;
        $gc->run(true);
    }

    private function seePresetGone(IntegrationTester $I, int $id): void
    {
        $I->assertFalse((new Query())->from(Preset::TABLE)->where(['id' => $id])->exists(), 'Row');
        $I->assertFalse((new Query())->from(Table::ELEMENTS)->where(['id' => $id])->exists(), 'Element');
    }

    /**
     * A preset with Topics and Writers chosen and filled.
     */
    private function presetWithTopicsAndWriters(IntegrationTester $I): Preset
    {
        return $I->preset('news', 'article', ['topics' => [$I->topic('Design')->id], 'writers' => [$I->admin()->id]]);
    }

    private function removeFromLayout(IntegrationTester $I, string $typeHandle, string $fieldHandle): void
    {
        $type = $I->entryType($typeHandle);
        $layout = $type->getFieldLayout();
        foreach ($layout->getTabs() as $tab) {
            $tab->setElements(array_values(array_filter(
                $tab->getElements(),
                fn($element) => !$element instanceof CustomField || $element->getField()->handle !== $fieldHandle,
            )));
        }
        $I->saveEntryType($type);
        $I->assertNull($I->entryType($typeHandle)->getFieldLayout()->getFieldByHandle($fieldHandle), "$fieldHandle left $typeHandle");
    }

    /**
     * Adds the field back as an admin does: a new layout element, with a new UID.
     */
    private function addToLayout(IntegrationTester $I, string $typeHandle, string $fieldHandle): void
    {
        $type = $I->entryType($typeHandle);
        $layout = $type->getFieldLayout();
        /** @var FieldLayoutTab $tab */
        $tab = $layout->getTabs()[0];
        $tab->setElements([
            ...$tab->getElements(),
            new CustomField(Craft::$app->getFields()->getFieldByHandle($fieldHandle)),
        ]);
        $I->saveEntryType($type);
    }

    /**
     * @param string[] $typeHandles
     */
    private function setEntryTypes(IntegrationTester $I, string $sectionHandle, array $typeHandles): void
    {
        $section = $I->section($sectionHandle);
        $section->setEntryTypes(array_map(fn(string $handle) => $I->entryType($handle), $typeHandles));
        $I->assertTrue(Craft::$app->getEntries()->saveSection($section));
    }
}
