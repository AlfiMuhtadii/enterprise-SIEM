<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOperationsHealth extends Model
{
    protected $table = 'customer_operations_health';

    public const HEALTH_STATES = ['healthy', 'degraded', 'at_risk', 'critical'];

    protected $fillable = [
        'health_record_id',
        'tenant_id',
        'health_score',
        'deployment_healthy',
        'telemetry_continuous',
        'support_ready',
        'onboarding_complete',
        'health_state',
        'health_evidence',
    ];

    protected $casts = [
        'health_score'         => 'float',
        'deployment_healthy'   => 'boolean',
        'telemetry_continuous' => 'boolean',
        'support_ready'        => 'boolean',
        'onboarding_complete'  => 'boolean',
        'health_evidence'      => 'array',
    ];
}
