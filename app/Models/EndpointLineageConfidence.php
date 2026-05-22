<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointLineageConfidence extends Model
{
    protected $table = 'endpoint_lineage_confidence';

    public const LINEAGE_TYPES = ['process', 'module', 'registry', 'socket', 'file_hash'];

    protected $fillable = [
        'confidence_id', 'endpoint_id', 'tenant_id', 'lineage_type',
        'confidence_score', 'baseline_confidence', 'degradation_delta',
        'degradation_detected', 'replay_safe', 'is_advisory', 'confidence_evidence',
    ];

    protected $casts = [
        'confidence_score'    => 'float',
        'baseline_confidence' => 'float',
        'degradation_delta'   => 'float',
        'degradation_detected'=> 'boolean',
        'replay_safe'         => 'boolean',
        'is_advisory'         => 'boolean',
        'confidence_evidence' => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointLineageConfidence is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
