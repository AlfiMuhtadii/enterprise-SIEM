<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointAntiEvasionIndicator extends Model
{
    public const INDICATOR_TYPES = [
        'telemetry_suppression', 'delayed_execution', 'parent_spoofing',
        'abnormal_ancestry', 'module_load_anomaly', 'runtime_divergence',
    ];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'indicator_id', 'endpoint_id', 'tenant_id', 'indicator_type', 'severity',
        'process_name', 'evasion_confirmed', 'confidence_score', 'replay_validated',
        'is_advisory', 'evasion_evidence',
    ];

    protected $casts = [
        'evasion_confirmed'  => 'boolean',
        'confidence_score'   => 'float',
        'replay_validated'   => 'boolean',
        'is_advisory'        => 'boolean',
        'evasion_evidence'   => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointAntiEvasionIndicator is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
