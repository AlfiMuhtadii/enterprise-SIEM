<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotOperationalReview extends Model
{
    public const REVIEW_TYPES = ['daily', 'escalation', 'false_positive', 'drift', 'replay_integrity', 'tenant_isolation'];
    public const VERDICTS      = ['acknowledged', 'escalated', 'deferred', 'closed'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'review_id', 'run_id', 'tenant_id', 'review_type', 'reviewed_by',
        'verdict', 'notes', 'requires_followup', 'is_advisory', 'evidence',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'requires_followup' => 'boolean',
        'is_advisory'       => 'boolean',
        'evidence'          => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotOperationalReview is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
