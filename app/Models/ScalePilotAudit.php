<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ScalePilotAudit extends Model
{
    protected $table = 'scale_pilot_audit';

    public const EVENT_TYPES = ['run_started', 'checkpoint', 'drift_detected', 'recovery', 'completion', 'aborted'];
    public const OUTCOMES    = ['success', 'failure', 'degraded', 'bounded'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'audit_id', 'run_id', 'tenant_id', 'event_type', 'actor',
        'outcome', 'description', 'is_advisory', 'payload',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'is_advisory' => 'boolean',
        'payload'     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ScalePilotAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
