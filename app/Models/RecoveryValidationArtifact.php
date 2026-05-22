<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecoveryValidationArtifact extends Model
{
    public const RECOVERY_TYPES = [
        'replay', 'telemetry', 'queue', 'worker', 'storage', 'graph', 'tenant',
    ];

    protected $fillable = [
        'artifact_id', 'simulation_id', 'run_id', 'recovery_type', 'recovery_ok',
        'recovery_seconds', 'duplicates_prevented', 'tenant_isolation_preserved',
        'graph_integrity_preserved', 'verdict', 'is_advisory',
    ];

    protected $casts = [
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
