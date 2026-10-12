<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

// Clear all compiled kernel caches before every test run so that
// InertiaBundle::configure() and loadExtension() are always executed
// from source (not from stale cached containers).
$baseCacheDir = sys_get_temp_dir().'/inertia-bundle-tests/cache';

if (is_dir($baseCacheDir)) {
    foreach (new DirectoryIterator($baseCacheDir) as $envDir) {
        if ($envDir->isDot() || !$envDir->isDir()) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($envDir->getPathname(), RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
}
