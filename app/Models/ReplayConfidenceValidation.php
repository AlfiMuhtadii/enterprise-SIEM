<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReplayConfidenceValidation extends Model
{
    public const VERDICTS = ['consistent', 'drifted', 'inconclusive'];

    protected $fillable = [
        'validation_id', 'rule_id', 'tenant_id', 'original_confidence',
        'replay_confidence', 'confidence_delta', 'replay_consistent',
        'verdict', 'is_advisory', 'replay_evidence',
    ];

    protected $casts = [
        'original_confidence' => 'float',
        'replay_confidence'   => 'float',
        'confidence_delta'    => 'float',
        'replay_consistent'   => 'boolean',
        'is_advisory'         => 'boolean',
        'replay_evidence'     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ReplayConfidenceValidation is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
