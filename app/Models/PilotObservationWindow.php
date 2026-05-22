<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PilotObservationWindow extends Model
{
    // MUTABLE — tracks live pilot observation window state
    public const PHASES    = ['24h', '48h', '72h', 'extended'];
    public const STATUSES  = ['pending', 'active', 'completed', 'aborted'];

    protected $fillable = [
        'window_id', 'run_id', 'tenant_id', 'duration_hours',
        'started_at', 'ends_at', 'status', 'health_ok',
        'metrics_meeting_targets', 'phase', 'health_snapshot',
    ];

    protected $casts = [
        'health_ok'               => 'boolean',
        'metrics_meeting_targets' => 'boolean',
        'health_snapshot'         => 'array',
        'started_at'              => 'datetime',
        'ends_at'                 => 'datetime',
    ];
}
