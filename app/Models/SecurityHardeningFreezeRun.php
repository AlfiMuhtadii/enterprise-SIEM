<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityHardeningFreezeRun extends Model
{
    protected $table = 'security_hardening_freeze_runs';

    protected $fillable = [
        'run_id', 'freeze_version', 'operator_id', 'run_state',
        'controls_total', 'controls_passed', 'controls_failed',
        'coverage_score', 'advisory_only', 'autonomous_certification',
        'self_approve_blocked', 'run_metadata', 'completed_at',
    ];

    protected $casts = [
        'advisory_only'            => 'boolean',
        'autonomous_certification' => 'boolean',
        'self_approve_blocked'     => 'boolean',
        'coverage_score'           => 'float',
        'run_metadata'             => 'array',
        'completed_at'             => 'datetime',
    ];

    public function checks(): HasMany
    {
        return $this->hasMany(SecurityHardeningFreezeCheck::class, 'run_id', 'run_id');
    }
}
