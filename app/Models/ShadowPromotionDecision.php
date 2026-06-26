<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of a shadow_ready rule promotion evaluation.
 * promotion_approved is always false — actual promotions require separate
 * ACTIVE_ALLOWLIST addition, domain 6h soak PASS, and human sign-off.
 */
class ShadowPromotionDecision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'decision_run_id',
        'rule_id',
        'domain',
        'current_status',
        'confidence',
        'decision',
        'false_positive_risk',
        'dlq_errors_in_domain',
        'advisory_findings_count',
        'evidence_basis',
        'promotion_approved',
        'is_advisory',
        'tenant_id',
        'evaluated_at',
    ];

    protected $casts = [
        'evidence_basis'   => 'array',
        'promotion_approved' => 'boolean',
        'is_advisory'      => 'boolean',
        'confidence'       => 'float',
    ];
}
