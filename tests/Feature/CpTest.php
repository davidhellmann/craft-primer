<?php

namespace davidhellmann\primer\tests\Feature;

use CraftCms\Cms\User\Models\User;
use CraftCms\Cms\Cp\Data\NavItem;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\Plugin;
use davidhellmann\primer\tests\Support\Content;
use davidhellmann\primer\tests\TestCase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function CraftCms\Cms\cp_url;

/**
 * Primer's control panel section: Craft's own element index of presets, and Craft's element editor for each preset,
 * as a page or in a slideout. Craft checks that a user may use the control panel; Primer's routes check *Access
 * Primer*.
 */
class CpTest extends TestCase
{
    use Content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->solo();
    }

    public function testGuestsAreSentToLogIn(): void
    {
        $preset = $this->preset('news', 'article');

        foreach (['primer', 'primer/presets/new', "primer/presets/$preset->id"] as $path) {
            $this->get(cp_url($path))->assertRedirect();
        }
    }

    public function testUsersWithoutAccessPrimerAreTurnedAway(): void
    {
        $preset = $this->preset('news', 'article');
        $this->actingAs($this->userWith(['accessCp']));

        foreach (['primer', 'primer/presets/new', "primer/presets/$preset->id"] as $path) {
            $this->get(cp_url($path))->assertForbidden();
        }
        $this->postJson(cp_url('actions/elements/save'), [
            'elementType' => Preset::class,
            'elementId' => $preset->id,
            'siteId' => $preset->siteId,
            'fieldUids' => [],
        ])->assertForbidden();
    }

    public function testTheIndexIsCraftsElementIndexOfPresets(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));

        $page = $this->inertia($this->get(cp_url('primer')))->component('elements/Index', shouldExist: false)
            ->where('elementType', Preset::class)
            ->where('newElement.href', cp_url('primer/presets/new'))
            ->toArray();

        // Topics' entry type has no relation fields: there's nothing to preset.
        $this->assertSame(['All presets', 'Sections', 'News'], $this->sourceLabels($page));
    }

    public function testPrimersNavItemListsTheIndexsSources(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));

        $item = Plugin::getInstance()->getCpNavItem();

        $this->assertInstanceOf(NavItem::class, $item);
        $this->assertSame(['All presets', 'Sections'], array_map(fn(NavItem $child) => $child->label, $item->subnav));
        $this->assertSame(['News'], array_map(fn(NavItem $child) => $child->label, $item->subnav[1]->subnav));
    }

    public function testTheIndexShowsEachPresetsSectionAndFieldsAlsoWhenEditingInline(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));
        $this->preset('news', 'article', ['topics' => [], 'writers' => []]);

        foreach ([[], ['editable' => 'true']] as $params) {
            $rows = $this->inertia($this->get(cp_url('primer', ['viewMode' => 'table', ...$params])))->toArray()['props']['data'];

            $this->assertSame('News', $rows[0]['section']);
            $this->assertSame('Topics, Writers', $rows[0]['fields']);
        }
    }

    public function testANewPresetStartsInTheIndexsSectionWithAnEntryTypeThatHasNoPresetYet(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));
        $this->preset('news', 'article');
        $news = $this->section('news');

        $response = $this->get(cp_url('primer/presets/new', [
            'returnUrl' => preg_replace('/\?/', ':QS:', cp_url('primer', ['source' => "section:$news->uid"]), 1),
        ]));

        $draft = Preset::find()->drafts()->one();
        $this->assertInstanceOf(Preset::class, $draft);
        $this->assertTrue($draft->getIsUnpublishedDraft());
        $this->assertSame($news->id, $draft->sectionId);
        $this->assertSame($this->entryType('note')->id, $draft->typeId);
        $response->assertRedirectContains("primer/presets/$draft->id");
    }

    public function testANewPresetPicksItsSectionAndEntryTypeAndTheFieldsFollow(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));
        $this->get(cp_url('primer/presets/new'));
        $draft = Preset::find()->drafts()->one();

        $this->assertSame(['sectionId', 'typeId', 'fieldUids'], $this->controlPaths($this->refresh($draft, [])));

        // Switching to News › Article: its relation fields are offered, none chosen yet.
        $ui = $this->refresh($draft, ['sectionId' => $this->section('news')->id, 'typeId' => $this->entryType('article')->id]);
        $this->assertSame(['Topics', 'Hero image', 'Writers', 'Related articles'], array_column($this->control($ui, 'fieldUids')['props']['options'], 'label'));
        $this->assertSame([], $ui['values']['fieldUids']);

        // Choosing two of them adds their inputs, in layout order.
        $ui = $this->refresh($draft, [
            'sectionId' => $this->section('news')->id,
            'typeId' => $this->entryType('article')->id,
            'fieldUids' => [$this->layoutElementUid('article', 'writers'), $this->layoutElementUid('article', 'topics')],
        ]);
        $this->assertSame(['sectionId', 'typeId', 'fieldUids', 'fields.topics', 'fields.writers'], $this->controlPaths($ui));
    }

    public function testCreatingAPresetStoresItsChoiceAndValues(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));
        $topic = $this->topic('Design');
        $this->get(cp_url('primer/presets/new'));
        $draft = Preset::find()->drafts()->one();

        $this->postJson(cp_url('actions/elements/apply-draft'), [
            'elementType' => Preset::class,
            'elementId' => $draft->id,
            'draftId' => $draft->draftId,
            'siteId' => $draft->siteId,
            'sectionId' => $this->section('news')->id,
            'typeId' => $this->entryType('article')->id,
            'fieldUids' => [$this->layoutElementUid('article', 'topics')],
            'fields' => ['topics' => [$topic->id]],
        ])->assertOk();

        $preset = Preset::find()->id($draft->id)->one();
        $this->assertInstanceOf(Preset::class, $preset);
        $this->assertFalse($preset->getIsDraft());
        $this->assertSame($this->entryType('article')->id, $preset->typeId);
        $this->assertSame([$this->layoutElementUid('article', 'topics')], $preset->getFieldUids());
        $this->assertSame([$topic->id], $this->ids($preset->getFieldValue('topics')));
    }

    public function testANewPresetOpensInCraftsElementEditor(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));
        $this->get(cp_url('primer/presets/new'));
        $draft = Preset::find()->drafts()->one();

        $props = $this->inertia($this->get($draft->getCpEditUrl()))
            ->component('elements/Edit', shouldExist: false)
            ->where('editorActions.primary.label', 'Create preset')
            ->toArray()['props'];

        $this->assertSame(['sectionId', 'typeId', 'fieldUids'], $this->controlPaths($props['ui']));
    }

    public function testThePresetScreenShowsTheFieldChoiceAndTheChosenFields(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));
        $preset = $this->preset('news', 'article', ['writers' => [$this->admin()->id]]);

        $props = $this->inertia($this->get(cp_url("primer/presets/$preset->id")))
            ->component('elements/Edit', shouldExist: false)
            ->where('title', 'Article')
            ->toArray()['props'];

        // A saved preset keeps its section and entry type; the sidebar says which they are.
        $this->assertSame(['fieldUids', 'fields.writers'], $this->controlPaths($props['ui']));
        $this->assertStringContainsString('News', $props['metadataHtml']);
        $this->assertSame(['Presets', 'News'], array_slice(array_column($props['crumbs'], 'label'), 0, 2));
    }

    public function testSavingThePresetScreenStoresTheChoiceAndValues(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));
        $topic = $this->topic('Design');
        $preset = $this->preset('news', 'article', ['writers' => [$this->admin()->id]]);

        $this->postJson(cp_url('actions/elements/save'), [
            'elementType' => Preset::class,
            'elementId' => $preset->id,
            'siteId' => $preset->siteId,
            'fieldUids' => [$this->layoutElementUid('article', 'topics')],
            'fields' => ['topics' => [$topic->id]],
        ])->assertOk();

        $stored = $this->stored($preset);
        $this->assertSame([$this->layoutElementUid('article', 'topics')], $stored->getFieldUids());
        $this->assertSame([$topic->id], $this->ids($stored->getFieldValue('topics')));
    }

    public function testDeletingAPresetFromTheScreen(): void
    {
        $this->actingAs($this->userWith(['accessCp', 'accessPlugin-primer']));
        $preset = $this->preset('news', 'article');

        $this->postJson(cp_url('actions/elements/delete'), [
            'elementType' => Preset::class,
            'elementId' => $preset->id,
            'siteId' => $preset->siteId,
        ])->assertOk();

        $this->assertNull(Preset::find()->id($preset->id)->one());
    }

    /**
     * A user who isn't an admin, with just these permissions.
     *
     * @param string[] $permissions
     */
    private function userWith(array $permissions): User
    {
        $element = $this->user('editor');
        \CraftCms\Cms\Support\Facades\UserPermissions::saveUserPermissions($element->id, $permissions);

        return User::query()->findOrFail($element->id);
    }

    private function inertia(TestResponse $response): AssertableInertia
    {
        return AssertableInertia::fromTestResponse($response->assertOk());
    }

    /**
     * The field layout redrawn for the values a new preset's form holds, as Craft's editor asks for it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function refresh(Preset $draft, array $values): array
    {
        $response = $this->postJson(cp_url('actions/elements/update-field-layout'), [
            'elementType' => Preset::class,
            'elementId' => $draft->id,
            'draftId' => $draft->draftId,
            'siteId' => $draft->siteId,
            ...$values,
        ])->assertOk();

        return $response->json('ui') ?? $response->json('data.ui');
    }

    /**
     * @param array<string, mixed> $ui
     * @return string[]
     */
    private function controlPaths(array $ui): array
    {
        $paths = [];
        $walk = function(array $nodes) use (&$walk, &$paths) {
            foreach ($nodes as $node) {
                if (isset($node['control']['path'])) {
                    $paths[] = implode('.', $node['control']['path']);
                }
                $walk($node['children'] ?? []);
                $walk($node['nodes'] ?? []);
            }
        };
        $walk($ui['nodes']);

        return $paths;
    }

    /**
     * @param array<string, mixed> $ui
     * @return array<string, mixed>
     */
    private function control(array $ui, string $path): array
    {
        $found = null;
        $walk = function(array $nodes) use (&$walk, &$found, $path) {
            foreach ($nodes as $node) {
                if (implode('.', $node['control']['path'] ?? []) === $path) {
                    $found = $node['control'];
                }
                $walk($node['children'] ?? []);
            }
        };
        $walk($ui['nodes']);
        $this->assertNotNull($found, "Control $path");

        return $found;
    }

    /**
     * @param array<string, mixed> $page
     * @return string[]
     */
    private function sourceLabels(array $page): array
    {
        return array_map(fn(array $source) => $source['label'] ?? $source['heading'] ?? '', $page['props']['sources']);
    }
}
