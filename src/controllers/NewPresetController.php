<?php

namespace davidhellmann\primer\controllers;

use CraftCms\Cms\Cp\RequestedSite;
use CraftCms\Cms\Element\Drafts;
use CraftCms\Cms\Element\Validation\ElementRules;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\Support\Url;
use davidhellmann\primer\elements\Preset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

use function CraftCms\Cms\t;

/**
 * Starts a preset as an unpublished draft and opens it in Craft's element editor, where its section and entry type
 * can still be changed until it's created.
 *
 * It starts in the site and section the index was showing (`returnUrl`), or `?site=` and `?sectionId=`, with the
 * first entry type that has no preset there yet, or `?typeId=`.
 */
readonly class NewPresetController
{
    public function __invoke(Request $request, Drafts $drafts, RequestedSite $requestedSite): RedirectResponse
    {
        $index = $this->indexParams($request);
        $site = $this->site($index['site'] ?? null) ?? $requestedSite->get();
        abort_unless($site !== null, 403, 'User not authorized to edit content in any sites.');

        $section = $this->section($request->integer('sectionId'), $index['source'] ?? null, $site);
        abort_unless($section !== null, 400, t('No section has entry types for this site.', category: 'primer'));

        $preset = new Preset([
            'siteId' => $site->id,
            'sectionId' => $section->id,
            'typeId' => $this->entryType($section, $site, $request->integer('typeId'))->id,
        ]);

        Gate::authorize('save', $preset);

        $preset->ruleset->useScenario(ElementRules::SCENARIO_ESSENTIALS);

        abort_unless(
            $drafts->saveElementAsDraft($preset, $request->craftUser()?->getCraftUserId(), markAsSaved: false),
            400,
            t('Couldn’t save preset.', category: 'primer'),
        );

        return redirect(Url::urlWithParams((string)$preset->getCpEditUrl(), array_filter([
            'fresh' => 1,
            'returnUrl' => $request->query('returnUrl'),
        ])));
    }

    /**
     * The query params of the index the button was pressed on, which Craft passes along as `returnUrl`.
     *
     * @return array<string, mixed>
     */
    private function indexParams(Request $request): array
    {
        $returnUrl = $request->query('returnUrl');
        if (!is_string($returnUrl)) {
            return [];
        }

        parse_str((string)parse_url(str_replace(':QS:', '?', $returnUrl), PHP_URL_QUERY), $params);

        return $params;
    }

    private function site(mixed $handle): ?Site
    {
        $site = is_string($handle) ? Sites::getSiteByHandle($handle) : null;

        return $site && Sites::getEditableSiteIds()->contains($site->id) ? $site : null;
    }

    /**
     * The section asked for, or the index's, or else the first that has entry types and is enabled for the site.
     */
    private function section(int $sectionId, mixed $source, Site $site): ?Section
    {
        $sections = Sections::getAllSections()->filter(fn(Section $section) => $section->getEntryTypes() !== []
            && isset($section->getSiteSettings()[$site->id]));

        $uid = is_string($source) && str_starts_with($source, 'section:') ? Str::after($source, 'section:') : null;

        return $sections->first(fn(Section $section) => $section->id === $sectionId)
            ?? $sections->first(fn(Section $section) => $section->uid === $uid)
            ?? $sections->first();
    }

    /**
     * The entry type asked for, or else the section's first that has no preset in the site yet, or else its first.
     */
    private function entryType(Section $section, Site $site, int $typeId): EntryType
    {
        $types = $section->getEntryTypes();
        $taken = Preset::find()->sectionId($section->id)->siteId($site->id)->get()->pluck('typeId')->all();

        return array_find($types, fn(EntryType $type) => $type->id === $typeId)
            ?? array_find($types, fn(EntryType $type) => !in_array($type->id, $taken, true))
            ?? $types[0];
    }
}
