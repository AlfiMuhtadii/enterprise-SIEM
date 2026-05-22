<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ChaosFailureEvent extends Model
{
    public const OUTCOMES = ['injected', 'detected', 'recovered', 'unrecovered'];
    public const COMPONENTS = ['worker', 'queue', 'storage', 'search', 'endpoint'];

    protected $fillable = [
        'event_id', 'simulation_id', 'failure_type', 'component',
        'offset_seconds', 'outcome', 'recovery_seconds', 'replay_safe', 'is_advisory',
    ];

    protected $casts = [
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
