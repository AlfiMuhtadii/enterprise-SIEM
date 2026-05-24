<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicalReadinessReport extends Model
{
    protected $fillable = [
        'tech_report_id', 'report_domain', 'domain_readiness_score',
        'soak_passed', 'replay_integrity_verified', 'contract_compliant',
        'rule_registry_valid', 'fleet_simulation_passed', 'is_advisory', 'tech_evidence',
    ];

    protected $casts = [
        'domain_readiness_score'    => 'float',
        'soak_passed'               => 'boolean',
        'replay_integrity_verified' => 'boolean',
        'contract_compliant'        => 'boolean',
        'rule_registry_valid'       => 'boolean',
        'fleet_simulation_passed'   => 'boolean',
        'is_advisory'               => 'boolean',
        'tech_evidence'             => 'array',
    ];

    public const REPORT_DOMAINS = [
        'correlation_engine', 'endpoint_agent', 'streaming_telemetry',
        'network_analytics', 'soar_governance',
    ];
}
