<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EnterpriseDeploymentAudit extends Model
{
    protected $table = 'enterprise_deployment_audit';

    public const ACTION_TYPES = [
        'package_validation', 'rollout_start', 'checkpoint_pass',
        'rollback_initiated', 'upgrade_applied', 'environment_check',
    ];

    public const OUTCOMES = ['success', 'failure', 'advisory'];

    protected $fillable = [
        'audit_id', 'deployment_id', 'action_type',
        'actor', 'outcome', 'is_advisory', 'audit_payload',
    ];

    protected $casts = [
        'is_advisory'  => 'boolean',
        'audit_payload'=> 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EnterpriseDeploymentAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
