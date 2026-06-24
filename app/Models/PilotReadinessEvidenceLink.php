<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotReadinessEvidenceLink extends Model
{
    protected $fillable = [
        'matrix_run_id', 'gate_eval_id', 'source_type', 'source_id',
        'evidence_data', 'linked_at',
    ];

    protected $casts = [
        'evidence_data' => 'array',
        'linked_at'     => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('pilot_readiness_evidence_links records are append-only and cannot be updated.');
        }
        return parent::save($options);
    }

    public function delete(): bool|null
    {
        throw new LogicException('pilot_readiness_evidence_links records cannot be deleted.');
    }

    public function forceDelete(): bool|null
    {
        throw new LogicException('pilot_readiness_evidence_links records cannot be deleted.');
    }
}
