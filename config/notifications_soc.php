<?php

return [
    'webhook_url' => env('SOC_WEBHOOK_URL'),
    'slack_url' => env('SOC_SLACK_URL'),
    'discord_url' => env('SOC_DISCORD_URL'),
    'max_attempts' => 3,
    'timeout_seconds' => 8,
];
