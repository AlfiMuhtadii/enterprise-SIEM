<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningFreezeCheck extends Model
{
    protected $table = 'security_hardening_freeze_checks';

    protected $fillable = [
        'check_id', 'run_id', 'control_id', 'control_category',
        'result', 'passed', 'detail', 'advisory_only',
        'check_metadata', 'evaluated_at',
    ];

    protected $casts = [
        'passed'         => 'boolean',
        'advisory_only'  => 'boolean',
        'check_metadata' => 'array',
        'evaluated_at'   => 'datetime',
    ];
}
