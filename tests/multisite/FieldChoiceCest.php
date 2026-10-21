<?php

namespace davidhellmann\primer\tests\multisite;

use Craft;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * The field choice is the same in every site and every scope (spec 4.4): a field taken out of it is emptied in all
 * of them, whatever its translation method, and starts empty when it is chosen again.
 *
 * Section `pagesSiteGroup` has two scopes: Corporate EN and DE, Shop EN and FR.
 */
class FieldChoiceCest
{
    private const SITES = ['corporate' => ['corporateEn', 'corporateDe'], 'shop' => ['shopEn', 'shopFr']];

    /**
     * The relation fields taken out of the choice, one per translation method, and the one that stays.
     */
    private const REMOVED = ['entriesNone', 'entriesSite', 'entriesSiteGroup', 'entriesLanguage'];
    private const KEPT = 'usersNone';

    public function _before(IntegrationTester $I): void
    {
        $I->amLoggedInAs($I->admin());
    }

    public function removingAFieldEmptiesItInEverySiteOfEveryScope(IntegrationTester $I): void
    {
        $presets = $this->presetsWithEveryFieldFilled($I);

        // Saved in Corporate DE, not the site the preset was created in.
        $this->save($I, $presets['corporate'], 'corporateDe', [self::KEPT]);

        foreach ($this->everySite($presets) as [$id, $site]) {
            foreach (self::REMOVED as $handle) {
                $I->assertSame([], $this->ids($id, $site, $handle), "$handle in $site");
            }
            $I->assertNotSame([], $this->ids($id, $site, self::KEPT), self::KEPT . " in $site");
            $I->assertSame([$I->layoutElementUid('page', self::KEPT)], $this->choice($id, $site), "Choice in $site");
        }
    }

    public function aPresetSavedDirectlyEmptiesTheRemovedFieldsEverywhereToo(IntegrationTester $I): void
    {
        $presets = $this->presetsWithEveryFieldFilled($I);

        // As any code saving the element, not the save action.
        $preset = Preset::find()->id($presets['corporate'])->site('corporateEn')->one();
        $preset->setFieldUids([$I->layoutElementUid('page', self::KEPT)]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($preset));

        foreach ($this->everySite($presets) as [$id, $site]) {
            foreach (self::REMOVED as $handle) {
                $I->assertSame([], $this->ids($id, $site, $handle), "$handle in $site");
            }
            $I->assertSame([$I->layoutElementUid('page', self::KEPT)], $this->choice($id, $site), "Choice in $site");
        }
    }

    public function aUserWhoMayEditOneSiteChangesTheChoiceAndDeletesInEveryScope(IntegrationTester $I): void
    {
        $presets = $this->presetsWithEveryFieldFilled($I);
        $I->amLoggedInAs($I->editor(['corporateEn']));

        // The permission is checked for the site saved in only; the choice reaches every scope, as Craft's entries do.
        $this->save($I, $presets['corporate'], 'corporateEn', [self::KEPT]);

        foreach ($this->everySite($presets) as [$id, $site]) {
            foreach (self::REMOVED as $handle) {
                $I->assertSame([], $this->ids($id, $site, $handle), "$handle in $site");
            }
            $I->assertSame([$I->layoutElementUid('page', self::KEPT)], $this->choice($id, $site), "Choice in $site");
        }

        $I->postAction('primer/presets/delete', ['presetId' => $presets['corporate'], 'siteId' => $I->site('corporateEn')->id]);

        $I->seeResponseCodeIs(200);
        $I->assertSame([], Preset::find()->id(array_values($presets))->site('*')->ids());
    }

    public function aFieldChosenAgainStartsEmpty(IntegrationTester $I): void
    {
        $presets = $this->presetsWithEveryFieldFilled($I);
        $this->save($I, $presets['corporate'], 'corporateEn', [self::KEPT]);

        // The form posts the chosen field's input, now empty.
        $this->save($I, $presets['shop'], 'shopFr', [self::KEPT, ...self::REMOVED], array_fill_keys(self::REMOVED, ''));

        foreach ($this->everySite($presets) as [$id, $site]) {
            foreach (self::REMOVED as $handle) {
                $I->assertSame([], $this->ids($id, $site, $handle), "$handle in $site");
            }
        }
    }

    public function aNewPresetInAnotherScopeTakesTheFieldOutOfTheOthers(IntegrationTester $I): void
    {
        $book = $I->book();
        $corporate = $I->preset('pagesSiteGroup', 'page', ['entriesSite' => [$book->id], self::KEPT => []], 'corporateEn');

        $response = $I->postAction('primer/presets/save', [
            'siteId' => $I->site('shopEn')->id,
            'sectionId' => $I->section('pagesSiteGroup')->id,
            'typeId' => $I->entryType('page')->id,
            'fieldUids' => [$I->layoutElementUid('page', self::KEPT)],
        ]);

        $I->assertSame('Preset saved.', $response['message'] ?? null);
        foreach (self::SITES['corporate'] as $site) {
            $I->assertSame([], $this->ids($corporate->id, $site, 'entriesSite'), $site);
        }
    }

    public function savingWithTheSameChoiceKeepsTheOtherSitesValues(IntegrationTester $I): void
    {
        $presets = $this->presetsWithEveryFieldFilled($I);

        $this->save($I, $presets['corporate'], 'corporateEn', [self::KEPT, ...self::REMOVED]);

        foreach (self::SITES['shop'] as $site) {
            foreach (self::REMOVED as $handle) {
                $I->assertNotSame([], $this->ids($presets['shop'], $site, $handle), "$handle in $site");
            }
        }
    }

    /**
     * One preset per scope, every field chosen and filled in every site.
     *
     * @return array{corporate: int, shop: int} Preset IDs by scope.
     */
    private function presetsWithEveryFieldFilled(IntegrationTester $I): array
    {
        $book = $I->book();
        $values = array_fill_keys(self::REMOVED, [$book->id]) + [self::KEPT => [$I->admin()->id]];

        $presets = [
            'corporate' => $I->preset('pagesSiteGroup', 'page', $values, 'corporateEn')->id,
            'shop' => $I->preset('pagesSiteGroup', 'page', $values, 'shopEn')->id,
        ];

        // The first save copied every value through the scope.
        foreach ($this->everySite($presets) as [$id, $site]) {
            foreach ([...self::REMOVED, self::KEPT] as $handle) {
                $I->assertNotSame([], $this->ids($id, $site, $handle), "$handle in $site before");
            }
        }

        return $presets;
    }

    /**
     * Saves a preset through the save action, as the preset screen does.
     *
     * @param string[] $handles The chosen fields.
     * @param array<string, mixed> $fields Posted field values.
     */
    private function save(IntegrationTester $I, int $presetId, string $site, array $handles, array $fields = []): void
    {
        $response = $I->postAction('primer/presets/save', [
            'siteId' => $I->site($site)->id,
            'presetId' => $presetId,
            'fieldUids' => array_map(fn(string $handle) => $I->layoutElementUid('page', $handle), $handles),
            'fields' => $fields,
        ]);

        $I->seeResponseCodeIs(200);
        $I->assertSame('Preset saved.', $response['message'] ?? null);
    }

    /**
     * @param array<string, int> $presets
     * @return array<array{int, string}> Each preset with each site of its scope.
     */
    private function everySite(array $presets): array
    {
        $pairs = [];
        foreach ($presets as $scope => $id) {
            foreach (self::SITES[$scope] as $site) {
                $pairs[] = [$id, $site];
            }
        }

        return $pairs;
    }

    /**
     * @return int[]
     */
    private function ids(int $presetId, string $site, string $handle): array
    {
        return Preset::find()->id($presetId)->site($site)->one()->getFieldValue($handle)->status(null)->ids();
    }

    /**
     * @return string[]
     */
    private function choice(int $presetId, string $site): array
    {
        return Preset::find()->id($presetId)->site($site)->one()->getFieldUids();
    }
}
