<?php
/**
 * This is the configuration file for the unit tests.
 * You can override configuration values by creating a `config.local.php` file
 * and manipulate the `$config` variable.
 */

$config = [
    'db' => [
        'dsn' => getenv('YII_TEST_DB_DSN') ?: 'sqlite::memory:',
        'username' => getenv('YII_TEST_DB_USERNAME') ?: null,
        'password' => getenv('YII_TEST_DB_PASSWORD') ?: null,
        'charset' => getenv('YII_TEST_DB_CHARSET') ?: 'utf8mb4',
    ],
];

if (is_file(__DIR__ . '/config.local.php')) {
    include(__DIR__ . '/config.local.php');
}

return $config;
