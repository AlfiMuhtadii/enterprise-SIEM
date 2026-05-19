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

    // Internal SOC/admin paths are always excluded so authenticated analysts
    // browsing their own dashboard do not generate false-positive alerts such
    // as ANOMALY_BEHAVIOR or EXPLOIT_CHAIN_SUSPECTED.
    'ignored_paths' => array_unique(array_filter(array_map(
        'trim',
        explode(',', env(
            'SECURITY_DETECTOR_IGNORED_PATHS',
            'up,health,health/*,livewire/update,.well-known/*,' .
            'soc,soc/*,security/alerts,security/alerts*,' .
            'scenario,scenario/*,admin,admin/*,profile,profile/*,dashboard,' .
            'entity,entity/*,entity-risk,entity-risk/*,api/entities,api/entities/*,api/entity-risk,api/entity-risk/*,' .
            'investigations,investigations/*,api/investigations,api/investigations/*,' .
            'response-plans,response-plans/*,api/response-plans,api/response-plans/*,' .
            'exports,exports/*,api/exports,api/exports/*,' .
            'security/hardening,api/internal,api/internal/*,' .
            'resilience,resilience/*,' .
            'endpoint-agents,endpoint-agents/*,api/agents,api/agents/*,' .
            'threat-hunts,threat-hunts/*,api/threat-hunts,api/threat-hunts/*,' .
            'integrations,integrations/*,api/integrations,api/integrations/*'
        ))
    ))),
];
