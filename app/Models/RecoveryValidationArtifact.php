<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecoveryValidationArtifact extends Model
{
    public const RECOVERY_TYPES = [
        'replay', 'telemetry', 'queue', 'worker', 'storage', 'graph', 'tenant',
    ];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'artifact_id', 'simulation_id', 'run_id', 'recovery_type', 'recovery_ok',
        'recovery_seconds', 'duplicates_prevented', 'tenant_isolation_preserved',
        'graph_integrity_preserved', 'verdict', 'is_advisory',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'recovery_ok'                 => 'boolean',
        'duplicates_prevented'        => 'boolean',
        'tenant_isolation_preserved'  => 'boolean',
        'graph_integrity_preserved'   => 'boolean',
        'is_advisory'                 => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('RecoveryValidationArtifact is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
