<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoScenarioRun extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'run_id', 'scenario_name', 'scenario_state', 'is_deterministic',
        'is_lab_safe', 'is_destructive', 'demo_mode', 'has_real_malware',
        'has_autonomous_remediation', 'event_count', 'run_duration_ms', 'run_output',
    ];

    protected $casts = [
        'is_deterministic'         => 'boolean',
        'is_lab_safe'              => 'boolean',
        'is_destructive'           => 'boolean',
        'demo_mode'                => 'boolean',
        'has_real_malware'         => 'boolean',
        'has_autonomous_remediation' => 'boolean',
        'run_output'               => 'array',
    ];

    const DEMO_SCENARIOS = [
        'phishing_attack_chain',
        'lolbin_execution_chain',
        'credential_abuse_chain',
        'lateral_movement_simulation',
        'cloud_saas_misuse',
        'noisy_enterprise_simulation',
        'analyst_overload_scenario',
        'replay_continuity_scenario',
        'ha_failover_governance_scenario',
        'deployment_governance_scenario',
    ];

    const SCENARIO_STATES = ['running', 'completed', 'failed'];
}
