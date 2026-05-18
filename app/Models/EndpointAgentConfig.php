<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndpointAgentConfig extends Model
{
    public const DEFAULT_CONFIG = [
        'policy_version'              => '1.0.0',
        'telemetry' => [
            'process'         => true,
            'network'         => true,
            'dns'             => true,
            'file'            => false,
            'scheduled_tasks' => true,
            'services'        => true,
        ],
        'batch_interval_seconds'       => 30,
        'buffer_size'                  => 1000,
        'max_buffer_size'              => 5000,
        'heartbeat_interval_seconds'   => 60,
        'allowed_watch_paths'          => [],
        'disk_pressure_threshold_mb'   => 100,
        'retry_max_attempts'           => 3,
        'retry_base_seconds'           => 1.0,
    ];

    protected $fillable = [
        'agent_id', 'policy_version', 'config', 'applied_at', 'created_by',
    ];

    protected $casts = [
        'config'     => 'array',
        'applied_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
