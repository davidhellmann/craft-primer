<?php

use craft\helpers\App;

// The test database. Set these in tests/.env (see tests/.env.example) or in the environment.
return [
    'driver' => App::env('CRAFT_DB_DRIVER') ?: 'mysql',
    'server' => App::env('CRAFT_DB_SERVER') ?: '127.0.0.1',
    'port' => App::env('CRAFT_DB_PORT') ?: null,
    'database' => App::env('CRAFT_DB_DATABASE') ?: 'primer_test',
    'user' => App::env('CRAFT_DB_USER') ?: 'root',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: '',
    'schema' => App::env('CRAFT_DB_SCHEMA') ?: 'public',
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX') ?: '',
];
