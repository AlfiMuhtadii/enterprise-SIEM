<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakRun extends Model
{
    protected $table = 'shadow_soak_runs';

    protected $fillable = [
        'run_id', 'domain', 'window_hours', 'status', 'dry_run',
        'started_at', 'completed_at', 'initiated_by', 'failure_reason',
        'gate_summary', 'evidence_pass', 'tenant_id',
    ];

    protected $casts = [
        'window_hours'   => 'integer',
        'dry_run'        => 'boolean',
        'evidence_pass'  => 'boolean',
        'gate_summary'   => 'array',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

    const STATUSES = ['running', 'completed', 'failed', 'dry_run'];
    const DOMAINS  = ['endpoint', 'network', 'ueba'];
}
