<?php

use craft\helpers\App;

// The integration suites' settings, but never their database: the install script drops every table in this one.
return array_merge(require dirname(__DIR__, 2) . '/_craft/config/db.php', [
    'database' => App::env('CRAFT_DB_DATABASE') ?: 'primer_browser',
]);
