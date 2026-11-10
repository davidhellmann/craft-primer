<?php

namespace davidhellmann\primer\controllers;

use CraftCms\Cms\Cp\SiteSwitcher;
use CraftCms\Cms\Http\Controllers\Elements\ElementTypeIndexController;
use CraftCms\Cms\Http\Requests\ElementIndexRequest;
use CraftCms\Cms\Support\Facades\Sites;
use Inertia\Response;

/**
 * Primer's index: Craft's element index of presets, about the site picked in Craft's site menu, as Craft's own
 * entry index is. Craft's element type index leaves the site menu out.
 */
readonly class PresetIndexController
{
    public function __invoke(
        ElementIndexRequest $request,
        SiteSwitcher $siteSwitcher,
        ElementTypeIndexController $index,
    ): Response {
        if (Sites::isMultiSite()) {
            $siteSwitcher->scopeToSite();
        }

        return $index($request);
    }
}
