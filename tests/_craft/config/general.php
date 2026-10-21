<?php

return [
    'devMode' => true,
    'securityKey' => 'primer-tests-only',
    // Control panel requests publish Craft's asset bundles; keep them out of the repo root.
    'resourceBasePath' => dirname(__DIR__) . '/storage/cpresources',
    // The test client doesn't carry Craft's CSRF cookie from one request to the next, so a posted form fails.
    'enableCsrfProtection' => false,
    'aliases' => [
        // The fixture's Images volume. Craft refuses local filesystems inside or above its system
        // directories, and the tests folder is one of them, so the files go to the temp dir.
        '@primerTestAssets' => sys_get_temp_dir() . '/primer-test-assets',
    ],
];
