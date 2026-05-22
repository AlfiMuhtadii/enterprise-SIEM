<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DetectionConfidenceHistory extends Model
{
    protected $table = 'detection_confidence_history';

    public const SOURCES = ['rule_base', 'replay_validated', 'analyst_adjusted', 'drift_adjusted'];

    protected $fillable = [
        'history_id', 'rule_id', 'tenant_id', 'confidence_value',
        'confidence_source', 'replay_consistent', 'drift_delta', 'is_advisory', 'metadata',
    ];

    protected $casts = [
        'confidence_value'  => 'float',
        'drift_delta'       => 'float',
        'replay_consistent' => 'boolean',
        'is_advisory'       => 'boolean',
        'metadata'          => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('DetectionConfidenceHistory is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
