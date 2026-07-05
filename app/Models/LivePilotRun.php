<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LivePilotRun extends Model
{
    public const STATUSES = ['pending', 'active', 'completed', 'aborted', 'rolled_back'];
    public const OBSERVATION_WINDOWS = [24, 48, 72];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'run_id', 'tenant_id', 'pilot_name', 'target_endpoint_count',
        'enrolled_endpoint_count', 'status', 'activation_approved', 'approved_by',
        'activated_at', 'completed_at', 'observation_window_hours',
        'rollback_ready', 'is_advisory', 'summary',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'activation_approved' => 'boolean',
        'rollback_ready'      => 'boolean',
        'is_advisory'         => 'boolean',
        'activated_at'        => 'datetime',
        'completed_at'        => 'datetime',
        'summary'             => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('LivePilotRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
