<?php

// Web entry of the browser suite's Craft, served by `php tests/browser/craft serve`.

require dirname(__DIR__) . '/bootstrap.php';

/** @var craft\web\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';
$app->run();
