<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakGateCheck extends Model
{
    protected $table = 'shadow_soak_gate_checks';

    protected $fillable = [
        'check_id', 'run_id', 'domain', 'gate_name',
        'result', 'actual_value', 'threshold_value', 'comparison', 'note',
        'tenant_id',
    ];

    protected $casts = [
        'actual_value'    => 'float',
        'threshold_value' => 'float',
    ];

    const RESULTS = ['pass', 'fail', 'skip'];
}
