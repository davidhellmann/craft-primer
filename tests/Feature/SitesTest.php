<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\Element\Enums\PropagationMethod;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\User\Models\User;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function CraftCms\Cms\cp_url;

/**
 * Presets in a multi-site Craft Pro: every screen is about one site, picked in Craft's site menu, and saving or
 * deleting a preset needs permission to edit its site. Each site switches a preset on or off with Craft's status
 * field.
 */
class SitesTest extends TestCase
{
    use Content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pro();
    }

    public function testTheIndexListsThePresetsOfTheRequestedSite(): void
    {
        $all = $this->preset('pagesAll', 'page', [], 'corporateEn');
        $shop = $this->preset('pagesNone', 'page', [], 'shopEn');
        $corporate = $this->preset('pagesNone', 'page', [], 'corporateDe');
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        $this->assertEqualsCanonicalizing([$all->id, $shop->id], $this->indexIds('shopEn'));
        $this->assertEqualsCanonicalizing([$all->id, $corporate->id], $this->indexIds('corporateDe'));
    }

    public function testTheSiteMenuListsTheSitesTheUserMayEdit(): void
    {
        $this->actingAs(User::query()->findOrFail($this->editor(['corporateEn', 'shopFr'])->id));

        $props = $this->inertia($this->get(cp_url('primer', ['site' => 'shopFr'])))->toArray()['props'];

        $this->assertSame(['corporateEn', 'shopFr'], array_column($props['sites'], 'handle'));
        // Craft's site crumb, which leads the screen's crumbs.
        $this->assertSame('Shop FR', $props['craft']['siteCrumb']['label'] ?? null);
    }

    public function testThePresetScreenShowsThePresetInTheRequestedSite(): void
    {
        $preset = $this->preset('pagesAll', 'page', [], 'corporateEn');
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        $this->inertia($this->get(cp_url("primer/presets/$preset->id", ['site' => 'shopFr'])))
            ->where('siteId', $this->site('shopFr')->id);
    }

    public function testTheStatusFieldHasASwitchPerSiteOfTheScopeTheUserMayEdit(): void
    {
        $preset = $this->preset('pagesSiteGroup', 'page', [], 'corporateEn');
        $this->actingAs(User::query()->findOrFail($this->editor(['corporateEn', 'corporateDe', 'shopEn'])->id));

        $sidebar = $this->inertia($this->get(cp_url("primer/presets/$preset->id", ['site' => 'corporateEn'])))
            ->toArray()['props']['sidebarUi'];

        // Corporate EN and DE: the preset's scope. Shop EN has a scope of its own.
        $this->assertSame(
            [$this->site('corporateEn')->id, $this->site('corporateDe')->id],
            array_keys($sidebar['values']['enabledForSite']),
        );
    }

    public function testANewPresetStartsInTheSiteTheIndexIsAbout(): void
    {
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        // Craft passes the index's URL along, its query marked so it survives as a param.
        $returnUrl = preg_replace('/\?/', ':QS:', cp_url('primer', ['site' => 'shopFr', 'source' => '*']), 1);
        $response = $this->get(cp_url('primer/presets/new', ['returnUrl' => $returnUrl]));

        $draft = Preset::find()->drafts()->siteId($this->site('shopFr')->id)->one();
        $this->assertInstanceOf(Preset::class, $draft);
        $response->assertRedirectContains('site=shopFr');
    }

    public function testSavingInASiteTheUserMayNotEditIsForbidden(): void
    {
        $preset = $this->preset('pagesNone', 'page', [], 'shopEn');
        $this->actingAs(User::query()->findOrFail($this->editor(['corporateEn'])->id));

        $this->postJson(cp_url('actions/elements/save'), [
            'elementType' => Preset::class,
            'elementId' => $preset->id,
            'siteId' => $this->site('shopEn')->id,
            'fieldUids' => [$this->layoutElementUid('page', 'usersNone')],
        ])->assertForbidden();

        $this->assertSame([], $this->stored($preset)->getFieldUids());
    }

    public function testDeletingInASiteTheUserMayNotEditIsForbidden(): void
    {
        $preset = $this->preset('pagesNone', 'page', [], 'shopEn');
        $this->actingAs(User::query()->findOrFail($this->editor(['corporateEn'])->id));

        $this->postJson(cp_url('actions/elements/delete'), [
            'elementType' => Preset::class,
            'elementId' => $preset->id,
            'siteId' => $this->site('shopEn')->id,
        ])->assertForbidden();

        $this->assertNotNull(Preset::find()->id($preset->id)->site('shopEn')->one());
    }

    public function testAPresetSwitchedOffInOneSiteOfItsScopeIsOnlyOffThere(): void
    {
        $preset = $this->preset('pagesSiteGroup', 'page', ['usersNone' => [$this->admin()->id]], 'corporateEn');
        $this->actingAs(User::query()->findOrFail($this->admin()->id));

        $this->postJson(cp_url('actions/elements/save'), [
            'elementType' => Preset::class,
            'elementId' => $preset->id,
            'siteId' => $this->site('corporateDe')->id,
            'enabledForSite' => [$this->site('corporateDe')->id => 0],
        ])->assertOk();

        $this->assertTrue($this->stored($preset, 'corporateEn')->isOn());
        $this->assertFalse($this->stored($preset, 'corporateDe')->isOn());
        $this->assertSame([], $this->ids($this->entry('pagesSiteGroup', 'page', [], 'corporateDe')->getFieldValue('usersNone')));
        $this->assertSame([$this->admin()->id], $this->ids($this->entry('pagesSiteGroup', 'page', [], 'corporateEn')->getFieldValue('usersNone')));
    }

    public function testAPresetOffAsAWholeStaysOffInTheSitesItsScopeGains(): void
    {
        $preset = $this->preset('pagesNone', 'page', ['usersNone' => [$this->admin()->id]], 'corporateEn');
        $off = $this->stored($preset);
        $off->enabled = false;
        $this->assertTrue(Elements::saveElement($off));

        $section = $this->section('pagesNone');
        $section->propagationMethod = PropagationMethod::SiteGroup;
        $this->assertTrue(Sections::saveSection($section));
        $this->assertTrue(Elements::saveElement($this->stored($preset)));

        $this->assertFalse($this->stored($preset, 'corporateDe')->isOn());
        $this->assertSame([], $this->ids($this->entry('pagesNone', 'page', [], 'corporateDe')->getFieldValue('usersNone')));
    }

    /**
     * The presets Primer's index lists in a site.
     *
     * @return int[]
     */
    private function indexIds(string $site): array
    {
        $props = $this->inertia($this->get(cp_url('primer', ['site' => $site, 'viewMode' => 'table'])))->toArray()['props'];

        return array_column($props['data'], 'id');
    }

    private function inertia(TestResponse $response): AssertableInertia
    {
        return AssertableInertia::fromTestResponse($response->assertOk());
    }
}
