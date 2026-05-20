<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernanceReviewFinding extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'finding_id', 'review_id', 'finding_type', 'severity', 'description',
        'evidence_links', 'remediation_required', 'is_advisory', 'trace_id',
        'created_by', 'created_at',
    ];

    protected $casts = [
        'evidence_links'       => 'array',
        'remediation_required' => 'boolean',
        'is_advisory'          => 'boolean',
        'created_at'           => 'datetime',
    ];

    public const FINDING_TYPES = [
        'stale_privilege', 'excess_access', 'policy_violation', 'pii_exposure', 'export_anomaly',
    ];

    public const SEVERITIES = ['informational', 'warning', 'critical'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('governance_review_findings is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
