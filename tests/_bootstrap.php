<?php

use craft\test\TestSetup;
use Dotenv\Dotenv;

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_ROOT_PATH', __DIR__ . '/_craft');
define('CRAFT_CONFIG_PATH', CRAFT_ROOT_PATH . '/config');
define('CRAFT_STORAGE_PATH', CRAFT_ROOT_PATH . '/storage');
define('CRAFT_TEMPLATES_PATH', CRAFT_ROOT_PATH . '/templates');
define('CRAFT_MIGRATIONS_PATH', CRAFT_ROOT_PATH . '/migrations');
define('CRAFT_TRANSLATIONS_PATH', CRAFT_ROOT_PATH . '/translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');

// Local database settings live in tests/.env; variables already set in the environment (CI) win.
Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();

TestSetup::configureCraft();
