<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeploymentObservabilitySnapshot extends Model
{
    protected $table = 'deployment_observability_snapshots';

    protected $fillable = [
        'snapshot_id', 'deployment_id', 'success_rate', 'rollout_duration_s',
        'restart_frequency', 'deployment_latency_ms',
        'replay_continuity_verified', 'is_advisory', 'observability_payload',
    ];

    protected $casts = [
        'replay_continuity_verified' => 'boolean',
        'is_advisory'                => 'boolean',
        'observability_payload'      => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('DeploymentObservabilitySnapshot is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
