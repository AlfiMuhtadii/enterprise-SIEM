<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EscalationQualityReview extends Model
{
    public const QUALITY_TIERS = ['high', 'medium', 'low', 'noise'];
    public const VERDICTS       = ['valid', 'over_escalated', 'under_escalated', 'noise'];

    protected $fillable = [
        'review_id', 'escalation_id', 'tenant_id', 'reviewed_by',
        'quality_score', 'quality_tier', 'evidence_sufficient', 'severity_appropriate',
        'replay_validated', 'verdict', 'is_advisory', 'review_notes',
    ];

    protected $casts = [
        'quality_score'        => 'float',
        'evidence_sufficient'  => 'boolean',
        'severity_appropriate' => 'boolean',
        'replay_validated'     => 'boolean',
        'is_advisory'          => 'boolean',
        'review_notes'         => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EscalationQualityReview is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
