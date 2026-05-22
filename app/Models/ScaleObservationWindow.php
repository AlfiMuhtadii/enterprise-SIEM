<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScaleObservationWindow extends Model
{
    public const ALLOWED_WINDOWS = [24, 48, 72];
    public const STATUSES         = ['active', 'completed', 'aborted'];

    protected $fillable = [
        'window_id', 'run_id', 'tenant_id', 'window_hours', 'status',
        'telemetry_continuity_pct', 'replay_recovery_success_pct',
        'drift_stability_pct', 'criteria_met', 'bounded_window', 'is_advisory', 'window_summary',
    ];

    protected $casts = [
        'telemetry_continuity_pct'    => 'float',
        'replay_recovery_success_pct' => 'float',
        'drift_stability_pct'         => 'float',
        'criteria_met'                => 'boolean',
        'bounded_window'              => 'boolean',
        'is_advisory'                 => 'boolean',
        'window_summary'              => 'array',
    ];
}
