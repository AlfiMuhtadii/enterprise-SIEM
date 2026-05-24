<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalystTriageSimulationRun extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'simulation_id', 'simulation_scenario', 'alerts_simulated', 'duplicates_handled',
        'chains_investigated', 'escalations_decided', 'dismissals_decided',
        'fp_markings', 'handoffs_simulated', 'triage_efficiency_score',
        'no_real_user_mutation', 'no_hidden_suppression', 'no_auto_incident_closure',
        'audit_trail',
    ];

    protected $casts = [
        'no_real_user_mutation'    => 'boolean',
        'no_hidden_suppression'    => 'boolean',
        'no_auto_incident_closure' => 'boolean',
        'audit_trail'              => 'array',
    ];

    const SIMULATION_SCENARIOS = [
        'alert_flood_triage',
        'duplicate_alert_handling',
        'attack_chain_investigation',
        'escalation_decision',
        'dismissal_decision',
        'false_positive_marking',
        'shift_handoff',
    ];
}
