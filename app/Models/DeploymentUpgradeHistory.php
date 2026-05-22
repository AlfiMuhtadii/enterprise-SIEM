<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeploymentUpgradeHistory extends Model
{
    protected $table = 'deployment_upgrade_history';

    protected $fillable = [
        'upgrade_id', 'from_version', 'to_version', 'migration_passed',
        'rollback_compatible', 'dependency_drift_detected',
        'compatibility_verified', 'replay_safe', 'is_advisory', 'upgrade_evidence',
    ];

    protected $casts = [
        'migration_passed'          => 'boolean',
        'rollback_compatible'       => 'boolean',
        'dependency_drift_detected' => 'boolean',
        'compatibility_verified'    => 'boolean',
        'replay_safe'               => 'boolean',
        'is_advisory'               => 'boolean',
        'upgrade_evidence'          => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('DeploymentUpgradeHistory is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
