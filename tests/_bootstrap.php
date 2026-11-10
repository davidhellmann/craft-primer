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

// The fixtures' project config has to name the schema version of the Craft that is installed, or Craft refuses to
// install it. The suites install copies of them that name it, so they run on every Craft 5 release.
$schemaVersion = (require CRAFT_VENDOR_PATH . '/craftcms/cms/src/config/app.php')['schemaVersion'];
foreach (['solo', 'pro'] as $fixture) {
    $source = __DIR__ . "/fixtures/$fixture";
    $copy = __DIR__ . "/_output/fixtures/$fixture";
    if (is_dir($copy)) {
        $old = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($copy, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($old as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $target = $copy . substr($file->getPathname(), strlen($source));
        is_dir(dirname($target)) || mkdir(dirname($target), 0777, true);
        copy($file->getPathname(), $target);
    }
    $yaml = (string)file_get_contents("$copy/project.yaml");
    file_put_contents("$copy/project.yaml", preg_replace('/^  schemaVersion: .+$/m', "  schemaVersion: $schemaVersion", $yaml, 1));
}

TestSetup::configureCraft();
