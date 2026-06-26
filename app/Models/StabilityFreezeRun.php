<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. freeze_approved is ALWAYS false. NEVER UPDATE or DELETE. */
class StabilityFreezeRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'freeze_run_id', 'freeze_version', 'phase_range',
        'total_gates', 'gates_passed', 'gates_failed', 'gates_warn',
        'pass_score', 'freeze_approved', 'is_advisory', 'tenant_id', 'frozen_at',
    ];

    protected $casts = [
        'freeze_approved' => 'boolean',
        'is_advisory'     => 'boolean',
        'pass_score'      => 'float',
    ];
}
