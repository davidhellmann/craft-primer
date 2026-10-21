<?php

namespace davidhellmann\primer\tests\multisite;

use Codeception\Attribute\DataProvider;
use Codeception\Example;
use Craft;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Site;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\IntegrationTester;

/**
 * Presets across sites, for every section propagation method and every field translation method (spec 4.2, 4.5,
 * section 8): each case saves a preset on site A, changes it on site B, then creates an entry on A and one on B,
 * switches the preset off on B and creates both again, and checks every site after each step. One of the related
 * entries exists in the Corporate sites only.
 *
 * The expected values come from the two tables below, written from the spec, not from Primer's code.
 */
class CrossSiteMatrixCest
{
    private const SITES = ['corporateEn', 'corporateDe', 'shopEn', 'shopFr'];

    /**
     * Per propagation method: the sites a preset (and a new entry) made on A spans, site B, and B's scope.
     * Sites: Corporate EN (en-US) and Corporate DE (de-DE) in one group, Shop EN (en-US) and Shop FR (fr-FR) in the other.
     */
    private const PROPAGATION = [
        'all' => [
            'section' => 'pagesAll',
            'scopeOfA' => ['corporateEn', 'corporateDe', 'shopEn', 'shopFr'],
            'b' => 'corporateDe',
            'scopeOfB' => ['corporateEn', 'corporateDe', 'shopEn', 'shopFr'],
        ],
        'siteGroup' => [
            'section' => 'pagesSiteGroup',
            'scopeOfA' => ['corporateEn', 'corporateDe'],
            'b' => 'corporateDe',
            'scopeOfB' => ['corporateEn', 'corporateDe'],
        ],
        'language' => [
            'section' => 'pagesLanguage',
            'scopeOfA' => ['corporateEn', 'shopEn'],
            'b' => 'shopEn',
            'scopeOfB' => ['corporateEn', 'shopEn'],
        ],
        'none' => [
            'section' => 'pagesNone',
            'scopeOfA' => ['corporateEn'],
            'b' => 'corporateDe',
            'scopeOfB' => ['corporateDe'],
        ],
        'custom' => [
            'section' => 'pagesCustom',
            'scopeOfA' => ['corporateEn'],
            'b' => 'corporateDe',
            'scopeOfB' => ['corporateDe'],
        ],
    ];

    /**
     * Per translation method: the field suffix, and the sets of sites that share one value.
     */
    private const TRANSLATION = [
        'none' => ['suffix' => 'None', 'sharing' => [['corporateEn', 'corporateDe', 'shopEn', 'shopFr']]],
        'site' => ['suffix' => 'Site', 'sharing' => [['corporateEn'], ['corporateDe'], ['shopEn'], ['shopFr']]],
        'siteGroup' => ['suffix' => 'SiteGroup', 'sharing' => [['corporateEn', 'corporateDe'], ['shopEn', 'shopFr']]],
        'language' => ['suffix' => 'Language', 'sharing' => [['corporateEn', 'shopEn'], ['corporateDe'], ['shopFr']]],
    ];

    /**
     * The sites the Corporate-only related entry exists in.
     */
    private const CORPORATE = ['corporateEn', 'corporateDe'];

    #[DataProvider('cases')]
    public function presetsAndNewEntriesBehaveLikeEntriesInEverySite(IntegrationTester $I, Example $example): void
    {
        $propagation = self::PROPAGATION[$example['propagation']];
        $sharing = self::TRANSLATION[$example['translation']]['sharing'];
        $suffix = self::TRANSLATION[$example['translation']]['suffix'];
        $fields = ["entries$suffix", "assets$suffix", "users$suffix"];
        $a = 'corporateEn';
        $b = $propagation['b'];

        $everywhere = $this->book($I, 'Everywhere', self::SITES);
        $corporateOnly = $this->book($I, 'Corporate only', self::CORPORATE);
        $later = $this->book($I, 'Later', self::SITES);
        $valuesOfA = [
            $fields[0] => [$everywhere->id, $corporateOnly->id],
            $fields[1] => [$I->image('a.png')->id],
            $fields[2] => [$this->user($I, 'alice')->id],
        ];
        $valuesOfB = [
            $fields[0] => [$later->id],
            $fields[1] => [$I->image('b.png')->id],
            $fields[2] => [$this->user($I, 'bob')->id],
        ];

        // 1. Saved on A: the preset is copied to every site of its scope, and nowhere else.
        $preset = $I->preset($propagation['section'], 'page', $valuesOfA, $a);

        foreach (self::SITES as $site) {
            $expected = in_array($site, $propagation['scopeOfA'], true) ? $this->inSite($valuesOfA, $site, $corporateOnly->id) : null;
            $I->assertSame($expected, $this->presetValues($preset->id, $site, $fields), "Preset saved on $a, in $site");
        }

        // 2. Changed on B: in A's scope, B edits A's preset; outside it, B's scope gets a preset of its own.
        $bInScope = in_array($b, $propagation['scopeOfA'], true);
        $presetOfB = $bInScope
            ? Preset::find()->id($preset->id)->site($b)->one()
            : new Preset(['sectionId' => $preset->sectionId, 'typeId' => $preset->typeId, 'siteId' => $I->site($b)->id]);
        $presetOfB->setFieldUids($preset->getFieldUids());
        $presetOfB->setFieldValues($valuesOfB);
        $I->saveElement($presetOfB);

        $after = [];
        foreach (self::SITES as $site) {
            if (in_array($site, $propagation['scopeOfA'], true)) {
                // A per-site value changes only where it is shared with B.
                $after[$site] = $bInScope && $this->shared($sharing, $site, $b) ? $valuesOfB : $valuesOfA;
                $id = $preset->id;
            } elseif (in_array($site, $propagation['scopeOfB'], true)) {
                $after[$site] = $valuesOfB;
                $id = $presetOfB->id;
            } else {
                $I->assertNull($this->presetValues($preset->id, $site, $fields), "No preset in $site");
                continue;
            }
            $I->assertSame($this->inSite($after[$site], $site, $corporateOnly->id), $this->presetValues($id, $site, $fields), "Preset changed on $b, in $site");
        }

        // 3. and 4. An entry created on A, and one on B, take that site's preset; Craft then copies the entry, values
        // included, to the sites a new entry of the section goes to. A related entry shows only where it exists.
        foreach ([$a => 'scopeOfA', $b => 'scopeOfB'] as $origin => $scope) {
            $entry = new Entry([
                'sectionId' => $preset->sectionId,
                'typeId' => $preset->typeId,
                'siteId' => $I->site($origin)->id,
                'title' => "Created on $origin",
            ]);
            $I->saveElement($entry);

            foreach (self::SITES as $site) {
                $expected = in_array($site, $propagation[$scope], true) ? $this->inSite($after[$origin], $site, $corporateOnly->id) : null;
                $I->assertSame($expected, $this->entryValues($entry->id, $site, $fields), "Entry created on $origin, in $site");
            }
        }

        // 5. Switched off on B with the lightswitch: an entry created on B is never filled, in no site Craft copies
        // it to; one created on A still is.
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->postAction('primer/presets/toggle', ['presetId' => $presetOfB->id, 'siteId' => $I->site($b)->id, 'enabled' => '0']);
        $empty = array_fill_keys($fields, []);

        foreach ([$b => 'scopeOfB', $a => 'scopeOfA'] as $origin => $scope) {
            $entry = new Entry([
                'sectionId' => $preset->sectionId,
                'typeId' => $preset->typeId,
                'siteId' => $I->site($origin)->id,
                'title' => "Created on $origin, $b switched off",
            ]);
            $I->saveElement($entry);

            foreach (self::SITES as $site) {
                $expected = in_array($site, $propagation[$scope], true)
                    ? ($origin === $b ? $empty : $this->inSite($after[$a], $site, $corporateOnly->id))
                    : null;
                $I->assertSame($expected, $this->entryValues($entry->id, $site, $fields), "Entry created on $origin with $b switched off, in $site");
            }
        }
    }

    /**
     * @return array<string, array{propagation: string, translation: string}>
     */
    protected function cases(): array
    {
        $cases = [];
        foreach (array_keys(self::PROPAGATION) as $propagation) {
            foreach (array_keys(self::TRANSLATION) as $translation) {
                $cases["$propagation propagation, $translation translation"] = compact('propagation', 'translation');
            }
        }

        return $cases;
    }

    /**
     * @param string[][] $sharing
     */
    private function shared(array $sharing, string $site, string $other): bool
    {
        foreach ($sharing as $sites) {
            if (in_array($site, $sites, true) && in_array($other, $sites, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The values as a site sees them: without the Corporate-only entry outside the Corporate sites.
     *
     * @param array<string, int[]> $values
     * @return array<string, int[]>
     */
    private function inSite(array $values, string $site, int $corporateOnlyId): array
    {
        if (in_array($site, self::CORPORATE, true)) {
            return $values;
        }

        return array_map(fn(array $ids) => array_values(array_diff($ids, [$corporateOnlyId])), $values);
    }

    /**
     * @param string[] $fields
     * @return array<string, int[]>|null The preset's IDs per field in the site, or null when it isn't in that site.
     */
    private function presetValues(int $id, string $site, array $fields): ?array
    {
        $preset = Preset::find()->id($id)->site($site)->one();

        return $preset ? $this->ids($preset->getFieldValues($fields)) : null;
    }

    /**
     * @param string[] $fields
     * @return array<string, int[]>|null
     */
    private function entryValues(int $id, string $site, array $fields): ?array
    {
        $entry = Entry::find()->id($id)->site($site)->status(null)->one();

        return $entry ? $this->ids($entry->getFieldValues($fields)) : null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, int[]>
     */
    private function ids(array $values): array
    {
        return array_map(fn(mixed $value) => $value instanceof ElementQueryInterface ? $value->status(null)->ids() : $value, $values);
    }

    /**
     * A Library entry that exists in the given sites only.
     *
     * @param string[] $sites
     */
    private function book(IntegrationTester $I, string $title, array $sites): Entry
    {
        $entry = new Entry([
            'sectionId' => $I->section('library')->id,
            'typeId' => $I->entryType('book')->id,
            'siteId' => $I->site($sites[0])->id,
            'title' => $title,
        ]);
        $entry->setEnabledForSite(array_fill_keys(array_map(fn(string $site) => $I->site($site)->id, $sites), true));
        $I->saveElement($entry);

        $I->assertEqualsCanonicalizing(
            array_map(fn(string $site) => $I->site($site)->id, $sites),
            array_map('intval', Entry::find()->id($entry->id)->site('*')->status(null)->select('elements_sites.siteId')->column()),
        );

        return $entry;
    }

    private function user(IntegrationTester $I, string $name): User
    {
        $user = new User(['username' => $name, 'email' => "$name@example.com"]);
        $I->saveElement($user);

        return $user;
    }
}
