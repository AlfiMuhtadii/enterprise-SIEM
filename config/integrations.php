<?php

/*
|--------------------------------------------------------------------------
| Enterprise Integration Adapters (advisory / simulated-by-default)
|--------------------------------------------------------------------------
| ENV-CACHE-DRIFT-BATCH: these were previously read via env() directly inside
| the adapter constructors, which returns null under `php artisan config:cache`
| (silently dropping credentials/URLs). Reading them here through config keeps
| the same env vars + defaults while remaining config-cache-safe.
|
| dry_run defaults to true so an unconfigured integration never performs a real
| outbound call.
*/

return [
    'jira' => [
        'url'         => env('XDR_JIRA_URL', ''),
        'email'       => env('XDR_JIRA_EMAIL', ''),
        'api_token'   => env('XDR_JIRA_API_TOKEN', ''),
        'project_key' => env('XDR_JIRA_PROJECT_KEY', 'SOC'),
        'dry_run'     => filter_var(env('XDR_JIRA_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],

    'pagerduty' => [
        'routing_key' => env('XDR_PAGERDUTY_ROUTING_KEY', ''),
        'dry_run'     => filter_var(env('XDR_PAGERDUTY_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],

    'servicenow' => [
        'url'      => env('XDR_SERVICENOW_URL', ''),
        'user'     => env('XDR_SERVICENOW_USER', ''),
        'password' => env('XDR_SERVICENOW_PASSWORD', ''),
        'dry_run'  => filter_var(env('XDR_SERVICENOW_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],

    'slack' => [
        'webhook_url' => env('XDR_SLACK_WEBHOOK_URL', ''),
        'dry_run'     => filter_var(env('XDR_SLACK_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],
];
