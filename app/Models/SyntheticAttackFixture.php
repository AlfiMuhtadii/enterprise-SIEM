<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyntheticAttackFixture extends Model
{
    // Mutable — update allowed to track fixture revisions; delete is forbidden
    protected $fillable = [
        'fixture_id', 'fixture_scenario', 'fixture_format', 'event_sequence',
        'trace_id', 'chain_id', 'mitre_tactics', 'total_events',
        'is_lab_safe', 'is_destructive', 'has_live_execution',
        'has_destructive_payload', 'fixture_state', 'description',
    ];

    protected $casts = [
        'event_sequence'      => 'array',
        'mitre_tactics'       => 'array',
        'is_lab_safe'         => 'boolean',
        'is_destructive'      => 'boolean',
        'has_live_execution'  => 'boolean',
        'has_destructive_payload' => 'boolean',
    ];

    const FIXTURE_SCENARIOS = [
        'phishing_to_script_execution',
        'encoded_powershell_chain',
        'lolbin_execution_chain',
        'credential_abuse_sequence',
        'lateral_movement_sequence',
        'persistence_chain',
        'defense_evasion_sequence',
        'cloud_saas_abuse_sequence',
        'container_runtime_drift_sequence',
        'multi_stage_attack_chain',
    ];

    const FIXTURE_STATES = ['draft', 'validated', 'archived'];
}
