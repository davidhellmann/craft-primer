<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;
use craft\enums\PropagationMethod;
use davidhellmann\primer\decisions\Scope;

/**
 * Which sites a preset spans: the sites a new entry of its section, created in the preset's site, would be saved to.
 */
class ScopeTest extends Unit
{
    /**
     * The section's sites: two site groups, three languages. 1 is English and German Corporate, 3 and 4 the Shop.
     */
    private const SITES = [
        1 => ['groupId' => 10, 'language' => 'en-US'],
        2 => ['groupId' => 10, 'language' => 'de-DE'],
        3 => ['groupId' => 20, 'language' => 'en-US'],
        4 => ['groupId' => 20, 'language' => 'fr-FR'],
    ];

    public function testAllSpansEverySiteOfTheSection(): void
    {
        $this->assertSame([1, 2, 3, 4], Scope::siteIds(PropagationMethod::All, 3, self::SITES));
    }

    public function testSiteGroupSpansTheSectionsSitesInTheSameGroup(): void
    {
        $this->assertSame([1, 2], Scope::siteIds(PropagationMethod::SiteGroup, 2, self::SITES));
        $this->assertSame([3, 4], Scope::siteIds(PropagationMethod::SiteGroup, 4, self::SITES));
    }

    public function testLanguageSpansTheSectionsSitesWithTheSameLanguage(): void
    {
        $this->assertSame([1, 3], Scope::siteIds(PropagationMethod::Language, 3, self::SITES));
        $this->assertSame([4], Scope::siteIds(PropagationMethod::Language, 4, self::SITES));
    }

    public function testNoneSpansOnlyThisSite(): void
    {
        $this->assertSame([2], Scope::siteIds(PropagationMethod::None, 2, self::SITES));
    }

    public function testCustomSpansOnlyThisSite(): void
    {
        // A new entry goes to the sites it's given a status for; a preset has no such choice, so it stays in one.
        $this->assertSame([4], Scope::siteIds(PropagationMethod::Custom, 4, self::SITES));
    }

    public function testOnlyTheSectionsSitesCount(): void
    {
        // The section isn't enabled for site 2: a group-wide preset made in site 1 stays there.
        $sites = self::SITES;
        unset($sites[2]);

        $this->assertSame([1], Scope::siteIds(PropagationMethod::SiteGroup, 1, $sites));
        $this->assertSame([1, 3, 4], Scope::siteIds(PropagationMethod::All, 1, $sites));
    }

    public function testASiteOutsideTheSectionSpansNothing(): void
    {
        $sites = self::SITES;
        unset($sites[2]);

        $this->assertSame([], Scope::siteIds(PropagationMethod::All, 2, $sites));
        $this->assertSame([], Scope::siteIds(PropagationMethod::None, 2, $sites));
    }
}
