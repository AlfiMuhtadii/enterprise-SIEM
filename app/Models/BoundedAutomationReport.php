<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BoundedAutomationReport extends Model
{
    protected $table = 'bounded_automation_reports';

    public const AUTOMATION_TYPES = [
        'health_check', 'dependency_check', 'recovery_readiness',
        'replay_continuity', 'queue_continuity', 'deployment_continuity',
    ];

    protected $fillable = [
        'automation_id', 'automation_type', 'service_scope',
        'check_passed', 'remediation_proposed', 'autonomous_action_taken',
        'is_advisory', 'automation_evidence',
    ];

    protected $casts = [
        'check_passed'             => 'boolean',
        'remediation_proposed'     => 'boolean',
        'autonomous_action_taken'  => 'boolean',
        'is_advisory'              => 'boolean',
        'automation_evidence'      => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('BoundedAutomationReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
