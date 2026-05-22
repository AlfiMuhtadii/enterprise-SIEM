<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperatorReadinessReview extends Model
{
    public const REVIEW_TYPES = [
        'runbook', 'escalation', 'shift_handoff', 'incident_workflow', 'general',
    ];

    protected $fillable = [
        'review_id', 'run_id', 'operator_id', 'review_type',
        'runbook_reviewed', 'escalation_validated', 'shift_handoff_ready',
        'incident_workflow_tested', 'operator_ready',
        'acknowledgment_latency_seconds', 'verdict', 'is_advisory',
    ];

    protected $casts = [
        'runbook_reviewed'          => 'boolean',
        'escalation_validated'      => 'boolean',
        'shift_handoff_ready'       => 'boolean',
        'incident_workflow_tested'  => 'boolean',
        'operator_ready'            => 'boolean',
        'is_advisory'               => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OperatorReadinessReview is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
