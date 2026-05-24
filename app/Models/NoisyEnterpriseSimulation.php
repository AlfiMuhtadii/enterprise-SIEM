<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoisyEnterpriseSimulation extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'simulation_id', 'noise_scenario', 'events_generated',
        'fp_resistance_score', 'alert_amplification_score', 'analyst_pressure_score',
        'telemetry_quality_under_noise', 'is_synthetic', 'is_lab_safe',
        'has_destructive_content', 'simulation_metrics',
    ];

    protected $casts = [
        'is_synthetic'           => 'boolean',
        'is_lab_safe'            => 'boolean',
        'has_destructive_content' => 'boolean',
        'simulation_metrics'     => 'array',
    ];

    const NOISE_SCENARIOS = [
        'patch_management_wave',
        'vpn_reconnect_storm',
        'admin_login_burst',
        'saas_audit_flood',
        'dns_burst',
        'software_deployment_storm',
        'endpoint_reconnect_surge',
    ];
}
