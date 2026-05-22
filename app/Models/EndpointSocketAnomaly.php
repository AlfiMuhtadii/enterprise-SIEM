<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EndpointSocketAnomaly extends Model
{
    public const ANOMALY_TYPES = [
        'repeated_reconnect', 'suspicious_port', 'unusual_destination',
        'long_lived_connection', 'beaconing_pattern',
    ];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'anomaly_id', 'endpoint_id', 'tenant_id', 'process_name', 'anomaly_type',
        'anomaly_score', 'severity', 'evasion_indicator', 'replay_validated',
        'is_advisory', 'anomaly_evidence',
    ];

    protected $casts = [
        'anomaly_score'     => 'float',
        'evasion_indicator' => 'boolean',
        'replay_validated'  => 'boolean',
        'is_advisory'       => 'boolean',
        'anomaly_evidence'  => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('EndpointSocketAnomaly is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
