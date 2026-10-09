<?php

/*
| Vercel serverless entry point (vercel-php runtime).
| The deployment filesystem is read-only except /tmp, so Laravel's storage and
| bootstrap caches are redirected there before the app boots.
*/

$storage = '/tmp/storage';

foreach (['framework/cache/data', 'framework/views', 'framework/sessions', 'logs', 'app/public'] as $dir) {
    if (! is_dir("$storage/$dir")) {
        mkdir("$storage/$dir", 0755, true);
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;

foreach ([
    'APP_CONFIG_CACHE' => '/tmp/config.php',
    'APP_EVENTS_CACHE' => '/tmp/events.php',
    'APP_PACKAGES_CACHE' => '/tmp/packages.php',
    'APP_ROUTES_CACHE' => '/tmp/routes.php',
    'APP_SERVICES_CACHE' => '/tmp/services.php',
    'VIEW_COMPILED_PATH' => "$storage/framework/views",
] as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv("$key=$value");
}

require __DIR__.'/../public/index.php';
