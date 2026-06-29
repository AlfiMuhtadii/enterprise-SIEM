<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningFreezeDeltaReport extends Model
{
    protected $table = 'security_hardening_freeze_delta_reports';

    protected $fillable = [
        'delta_id', 'current_run_id', 'previous_run_id',
        'controls_added', 'controls_removed', 'controls_regressed',
        'controls_improved', 'score_delta', 'regression_detected', 'delta_metadata',
    ];

    protected $casts = [
        'regression_detected' => 'boolean',
        'score_delta'         => 'float',
        'delta_metadata'      => 'array',
    ];
}
