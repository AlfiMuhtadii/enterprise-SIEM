<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotDriftReview extends Model
{
    public const DRIFT_TYPES  = ['telemetry', 'queue', 'memory', 'replay', 'schema'];
    public const SEVERITIES   = ['low', 'medium', 'high', 'critical'];
    public const VERDICTS     = ['stable', 'monitoring', 'escalated', 'rolled_back'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'drift_review_id', 'run_id', 'tenant_id', 'drift_type', 'drift_magnitude',
        'drift_severity', 'verdict', 'reviewed_by', 'rollback_triggered', 'is_advisory', 'snapshot',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'drift_magnitude'   => 'float',
        'rollback_triggered'=> 'boolean',
        'is_advisory'       => 'boolean',
        'snapshot'          => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotDriftReview is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
