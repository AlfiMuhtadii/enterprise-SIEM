<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutiveReadinessReport extends Model
{
    protected $fillable = [
        'exec_report_id', 'report_scope', 'overall_readiness_score',
        'gates_passed', 'gates_total', 'limitations_open', 'risks_high',
        'go_live_recommendation', 'autonomous_approval', 'self_approve_blocked',
        'is_advisory', 'exec_evidence',
    ];

    protected $casts = [
        'overall_readiness_score' => 'float',
        'gates_passed'            => 'integer',
        'gates_total'             => 'integer',
        'limitations_open'        => 'integer',
        'risks_high'              => 'integer',
        'autonomous_approval'     => 'boolean',
        'self_approve_blocked'    => 'boolean',
        'is_advisory'             => 'boolean',
        'exec_evidence'           => 'array',
    ];

    public const REPORT_SCOPES = [
        'identity_cloud', 'endpoint_shadow', 'network_shadow', 'full_platform',
    ];

    public const GO_LIVE_RECOMMENDATIONS = ['approved', 'conditional', 'deferred', 'blocked'];
}
