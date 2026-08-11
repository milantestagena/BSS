<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Angular frontend (127.0.0.1:4837 in dev) calls the GraphQL endpoint
    | from a different origin than the backend (127.0.0.1:8000), so /graphql
    | needs to be in `paths` alongside the framework's api/* default.
    |
    | `allowed_origins` must be an explicit list (not '*') and
    | `supports_credentials` must be true for the Google OAuth session cookie
    | to actually reach the backend on cross-port GraphQL calls — bug caught
    | 2026-08-10: login succeeded but `me` always came back null locally,
    | since the browser silently drops the session cookie on a credentialed
    | cross-origin request when the server allows '*' origins (CORS spec
    | forbids combining wildcard origins with credentials). Production
    | doesn't strictly need this (frontend/backend share one origin there),
    | but listing it explicitly costs nothing and keeps this file honest.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'graphql'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://127.0.0.1:4837',
        // Browsers treat 'localhost' and '127.0.0.1' as different CORS origins even though
        // they're the same machine — graphql.service.ts documents why 127.0.0.1 is the
        // intended one (WSL2 IPv4 port-forwarding), but 'localhost' is easy to type out of
        // habit/history, so it's whitelisted too rather than relying on everyone remembering.
        // Bug caught live 2026-08-11: opened via localhost:4837, GraphQL blocked outright.
        'http://localhost:4837',
        'https://tripinele.com',
        'https://www.tripinele.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
