<?php

// The integration suites' settings, with what a served control panel needs on top.
return array_merge(require dirname(__DIR__, 2) . '/_craft/config/general.php', [
    // `php craft serve` routes every path to index.php.
    'omitScriptNameInUrls' => true,
    // Asset bundles must be reachable over HTTP.
    'resourceBasePath' => '@webroot/cpresources',
    'resourceBaseUrl' => '@web/cpresources',
    // A browser carries Craft's CSRF cookie, so the CP runs as in production.
    'enableCsrfProtection' => true,
]);
