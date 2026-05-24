<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionRiskRegister extends Model
{
    protected $table = 'production_risk_register';

    protected $fillable = [
        'risk_id', 'risk_category', 'risk_severity', 'risk_score',
        'risk_description', 'risk_state', 'cutover_blocker',
        'autonomous_mitigation', 'is_advisory', 'risk_evidence',
    ];

    protected $casts = [
        'risk_score'           => 'float',
        'cutover_blocker'      => 'boolean',
        'autonomous_mitigation'=> 'boolean',
        'is_advisory'          => 'boolean',
        'risk_evidence'        => 'array',
    ];

    public const RISK_CATEGORIES = [
        'replay_failure', 'contract_drift', 'cutover_premature',
        'soak_skipped', 'capacity_overrun',
    ];

    public const RISK_SEVERITIES = ['low', 'medium', 'high', 'critical'];
    public const RISK_STATES     = ['open', 'mitigated', 'accepted', 'resolved'];
}
