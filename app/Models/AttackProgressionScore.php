<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AttackProgressionScore extends Model
{
    protected $fillable = [
        'score_id', 'tenant_id', 'attack_chain_id', 'tactic_sequence',
        'tactic_count', 'progression_score', 'confidence_score',
        'chained_confirmed', 'replay_validated', 'is_advisory', 'chain_evidence',
    ];

    protected $casts = [
        'progression_score'  => 'float',
        'confidence_score'   => 'float',
        'chained_confirmed'  => 'boolean',
        'replay_validated'   => 'boolean',
        'is_advisory'        => 'boolean',
        'chain_evidence'     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AttackProgressionScore is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
