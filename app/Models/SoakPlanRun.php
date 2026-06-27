<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ENTERPRISE-060: Soak Execution Plan run record (append-only). */
class SoakPlanRun extends Model
{
    protected $table      = 'soak_plan_runs';
    public    $timestamps = false;

    protected $fillable = [
        'plan_run_id', 'phases_total', 'phases_ready', 'phases_partial', 'phases_blocked',
        'total_gates', 'gates_passed', 'overall_readiness', 'real_execution_gated',
        'is_advisory', 'tenant_id', 'created_at',
    ];

    protected $casts = [
        'real_execution_gated' => 'boolean',
        'is_advisory'          => 'boolean',
    ];
}
