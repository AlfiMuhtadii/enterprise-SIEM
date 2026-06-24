<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotReadinessGateEvaluation extends Model
{
    protected $fillable = [
        'matrix_run_id', 'gate_id', 'dimension', 'status',
        'detail', 'score', 'evidence', 'evaluated_at',
    ];

    protected $casts = [
        'evidence'     => 'array',
        'score'        => 'float',
        'evaluated_at' => 'datetime',
    ];

    public const DIMENSIONS = ['technical', 'operational', 'security', 'telemetry', 'easm', 'certification'];
    public const STATUSES   = ['pass', 'warn', 'fail', 'skip'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('pilot_readiness_gate_evaluations records are append-only and cannot be updated.');
        }
        return parent::save($options);
    }

    public function delete(): bool|null
    {
        throw new LogicException('pilot_readiness_gate_evaluations records cannot be deleted.');
    }

    public function forceDelete(): bool|null
    {
        throw new LogicException('pilot_readiness_gate_evaluations records cannot be deleted.');
    }
}
