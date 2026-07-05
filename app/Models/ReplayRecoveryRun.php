<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReplayRecoveryRun extends Model
{
    public const TRIGGERS = ['worker_restart', 'queue_disconnect', 'storage_recovery', 'manual'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'run_id', 'trigger', 'events_pending', 'events_replayed', 'ordering_preserved',
        'duplicates_prevented', 'tenant_isolation_preserved', 'continuity_verified',
        'replay_seconds', 'verdict', 'is_advisory',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'ordering_preserved'          => 'boolean',
        'duplicates_prevented'        => 'boolean',
        'tenant_isolation_preserved'  => 'boolean',
        'continuity_verified'         => 'boolean',
        'is_advisory'                 => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ReplayRecoveryRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
