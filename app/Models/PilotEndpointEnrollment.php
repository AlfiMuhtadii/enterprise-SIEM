<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotEndpointEnrollment extends Model
{
    public const STATUSES = ['enrolling', 'enrolled', 'failed', 'withdrawn'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'enrollment_id', 'run_id', 'tenant_id', 'endpoint_id', 'hostname',
        'status', 'onboarding_verified', 'telemetry_flowing',
        'telemetry_continuity_pct', 'is_advisory', 'metadata',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'onboarding_verified'      => 'boolean',
        'telemetry_flowing'        => 'boolean',
        'is_advisory'              => 'boolean',
        'telemetry_continuity_pct' => 'float',
        'metadata'                 => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotEndpointEnrollment is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
