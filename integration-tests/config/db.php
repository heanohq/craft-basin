<?php

use craft\helpers\App;

// phpunit.xml pins CRAFT_DB_DATABASE to basin_tests before Craft boots, so a
// host's own CRAFT_DB_* (DDEV sets them for the sandbox site) can't point the
// tests at a real database. Server and credentials come from the environment:
// DDEV's in the sandbox, the MySQL service's in CI.
return [
    'driver' => 'mysql',
    'server' => App::env('CRAFT_DB_SERVER') ?: 'db',
    'port' => App::env('CRAFT_DB_PORT') ?: 3306,
    'database' => 'basin_tests',
    'user' => App::env('CRAFT_DB_USER') ?: 'db',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: 'db',
    'tablePrefix' => '',
];
