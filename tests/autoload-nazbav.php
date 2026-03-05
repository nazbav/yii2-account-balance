<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'nazbav\\balance\\' => dirname(__DIR__) . '/src/',
        'nazbav\\tests\\unit\\balance\\' => __DIR__ . '/',
    ];

    foreach ($prefixes as $prefix => $basePath) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relativePath = substr($class, strlen($prefix));
        $filePath = $basePath . str_replace('\\', '/', $relativePath) . '.php';
        if (is_file($filePath)) {
            require_once $filePath;
        }

        return;
    }
});
