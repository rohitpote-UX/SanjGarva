<?php

/*
| The React PWA (e.g. on Vercel) calls this API from another origin using Bearer tokens.
| Set FRONTEND_URL to a comma-separated list of allowed origins, e.g.
| FRONTEND_URL=https://saanj-garva.vercel.app,http://localhost:5173
*/

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        [
            'https://sanjgarva.vercel.app',
            'https://*.vercel.app',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
        ],
        array_map('trim', explode(',', (string) env('FRONTEND_URL', '')))
    )))),

    // Automatically allow production domain and any *.vercel.app preview deployments
    'allowed_origins_patterns' => array_values(array_filter([
        '#^https:\/\/[a-zA-Z0-9-]+\.vercel\.app\z#u',
        env('FRONTEND_URL_PATTERN', null),
    ])),

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Locale', 'X-Requested-With'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 86400,

    'supports_credentials' => false,
];
