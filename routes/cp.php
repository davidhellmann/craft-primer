<?php

/**
 * Control panel routes. Craft registers them for logged-in users who may use the control panel, and checks nothing
 * else, so they require *Access Primer* on top. The index and the preset screen are Craft's own element index and
 * element editor, for presets.
 */

use CraftCms\Cms\Http\Controllers\Elements\EditElementController;
use davidhellmann\primer\controllers\NewPresetController;
use davidhellmann\primer\controllers\PresetIndexController;
use davidhellmann\primer\elements\Preset;
use davidhellmann\primer\Plugin;
use Illuminate\Support\Facades\Route;

Route::middleware('can:'.Plugin::ACCESS_PERMISSION)->group(function() {
    Route::get('primer', PresetIndexController::class)
        ->defaults('elementType', Preset::class)
        ->defaults('newElementRoute', 'primer.presets.new')
        ->name('primer.index');
    Route::get('primer/presets/new', NewPresetController::class)
        ->name('primer.presets.new');
    Route::get('primer/presets/{id}', EditElementController::class)
        ->whereNumber('id')
        ->defaults('elementType', Preset::class)
        ->name('primer.presets.edit');
});
