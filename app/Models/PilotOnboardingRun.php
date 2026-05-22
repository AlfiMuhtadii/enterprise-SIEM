<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotOnboardingRun extends Model
{
    public const STATUSES = ['pending', 'approved', 'onboarding', 'active', 'paused', 'aborted'];

    protected $fillable = [
        'run_id', 'tenant_id', 'status', 'approval_ref',
        'max_events_per_second', 'max_endpoints', 'pilot_duration_hours',
        'readiness_checklist_complete', 'rollback_drill_complete',
        'operator_acknowledged', 'operator_id', 'is_advisory', 'evidence_refs',
    ];

    protected $casts = [
        'readiness_checklist_complete' => 'boolean',
        'rollback_drill_complete'      => 'boolean',
        'operator_acknowledged'        => 'boolean',
        'is_advisory'                  => 'boolean',
        'evidence_refs'                => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotOnboardingRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
