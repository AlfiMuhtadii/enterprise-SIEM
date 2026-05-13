<?php

return [
    'response_hours' => [
        'critical' => 1,
        'high' => 4,
        'medium' => 12,
        'low' => 24,
    ],
    'resolution_hours' => [
        'critical' => 8,
        'high' => 24,
        'medium' => 72,
        'low' => 168,
    ],
    'escalation_thresholds' => [
        'critical' => [0.5, 1.0],
        'high' => [0.75, 1.0],
        'medium' => [1.0],
        'low' => [1.0],
    ],
];
