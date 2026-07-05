<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ChaosFailureEvent extends Model
{
    public const OUTCOMES = ['injected', 'detected', 'recovered', 'unrecovered'];
    public const COMPONENTS = ['worker', 'queue', 'storage', 'search', 'endpoint'];

    // SIM-LAYER-REALITY-GATE: model-level defaults so freshly created
    // instances carry the simulated/computed label immediately in-memory
    // (Eloquent does not re-fetch DB column defaults after INSERT).
    protected $attributes = [
        'is_simulated' => true,
        'evidence_basis' => 'computed',
    ];

    protected $fillable = [
        'event_id', 'simulation_id', 'failure_type', 'component',
        'offset_seconds', 'outcome', 'recovery_seconds', 'replay_safe', 'is_advisory',
        'is_simulated',
        'evidence_basis',
    ];

    protected $casts = [
        'is_simulated' => 'boolean',
        'replay_safe' => 'boolean',
        'is_advisory' => 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ChaosFailureEvent is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
