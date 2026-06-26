<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ENTERPRISE-050: Append-only batch plan snapshot per domain+tier.
 * NEVER UPDATE or DELETE rows.
 */
class RuleEvidenceBatchPlan extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id', 'domain', 'priority_tier', 'rules_count',
        'missing_fixture_count', 'missing_evidence_count',
        'estimated_effort_days', 'rule_ids',
        'plan_approved', 'is_advisory', 'tenant_id',
    ];

    protected $casts = [
        'rules_count'            => 'integer',
        'missing_fixture_count'  => 'integer',
        'missing_evidence_count' => 'integer',
        'estimated_effort_days'  => 'integer',
        'rule_ids'               => 'array',
        'plan_approved'          => 'boolean',
        'is_advisory'            => 'boolean',
        'generated_at'           => 'datetime',
    ];
}
