<?php

// The browser suite's Craft: a real, served Craft next to the integration suites' one. It shares their config and
// fixtures but has its own database (`primer_browser` unless CRAFT_DB_DATABASE says otherwise), storage, project
// config folder and web root, so a Codeception run doesn't change what the browser sees.

use Dotenv\Dotenv;

define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', dirname(__DIR__, 2) . '/vendor');
define('CRAFT_TEMPLATES_PATH', dirname(__DIR__) . '/_craft/templates');
define('CRAFT_MIGRATIONS_PATH', dirname(__DIR__) . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', dirname(__DIR__) . '/_craft/translations');

require_once CRAFT_VENDOR_PATH . '/autoload.php';

// Local database settings live in tests/browser/.env; variables already set in the environment (CI) win.
Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();
