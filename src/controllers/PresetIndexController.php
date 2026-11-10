<?php

namespace davidhellmann\primer\controllers;

use CraftCms\Cms\Cp\SiteSwitcher;
use CraftCms\Cms\Http\Requests\ElementIndexRequest;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\viewmodels\PresetIndexViewModel;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

use function CraftCms\Cms\t;

/**
 * Primer's index: Craft's element index of presets (`PresetIndexViewModel`), about the site picked in Craft's site
 * menu, as Craft's own entry index is, with a button for a new preset.
 */
readonly class PresetIndexController
{
    public function __invoke(ElementIndexRequest $request, SiteSwitcher $siteSwitcher): Response
    {
        $viewModel = new PresetIndexViewModel(Preset::class, $request);

        if ($viewModel->showSiteMenu()) {
            $siteSwitcher->scopeToSite();
        }

        return Inertia::render('elements/Index', [$viewModel])
            ->with([
                'indexUrl' => $request->url(),
                'newElement' => Gate::allows('save', new Preset())
                    ? ['label' => t('New preset', category: 'primer'), 'href' => route('primer.presets.new')]
                    : null,
            ]);
    }
}
