<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TenantReplayValidationRun extends Model
{
    protected $fillable = [
        'validation_id', 'tenant_id', 'replay_id', 'replay_isolated',
        'ordering_deterministic', 'events_replayed', 'cross_tenant_detected',
        'verdict', 'lineage_refs', 'is_advisory',
    ];

    protected $casts = [
        'replay_isolated'        => 'boolean',
        'ordering_deterministic' => 'boolean',
        'is_advisory'            => 'boolean',
        'lineage_refs'           => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TenantReplayValidationRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
