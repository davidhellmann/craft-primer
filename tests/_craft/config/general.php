<?php

return [
    'devMode' => true,
    'securityKey' => 'primer-tests-only',
    // Control panel requests publish Craft's asset bundles; keep them out of the repo root.
    'resourceBasePath' => dirname(__DIR__) . '/storage/cpresources',
];
