<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Security Event Logging
    |--------------------------------------------------------------------------
    |
    | These options make the application-side collector portable. A client
    | Laravel app only needs to emit JSONL events that match the v1 contract;
    | the detector engine can run outside the client source tree.
    |
    */

    'enabled' => env('SECURITY_DETECTOR_ENABLED', true),

    'log_path' => env('SECURITY_DETECTOR_LOG_PATH', storage_path('logs/security.jsonl')),

    'hash_key' => env('SECURITY_DETECTOR_HASH_KEY', env('APP_KEY', 'demo-fallback-key')),

    'capture_query_paths' => array_filter(array_map(
        'trim',
        explode(',', env('SECURITY_DETECTOR_CAPTURE_QUERY_PATHS', 'search,/search,api/search,/api/search,products,/products'))
    )),

    'ignored_paths' => array_filter(array_map(
        'trim',
        explode(',', env('SECURITY_DETECTOR_IGNORED_PATHS', 'up,health,livewire/update,.well-known/*'))
    )),
];
