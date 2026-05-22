<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalIntelligenceSnapshot extends Model
{
    public const SNAPSHOT_TYPES = ['daily', 'weekly', 'incident_driven', 'replay'];

    protected $fillable = [
        'snapshot_id', 'tenant_id', 'snapshot_type', 'active_rules', 'shadow_rules',
        'avg_confidence', 'alert_count', 'false_positive_count', 'false_positive_rate',
        'chained_detections', 'coverage_score', 'is_advisory', 'summary',
    ];

    protected $casts = [
        'avg_confidence'      => 'float',
        'false_positive_rate' => 'float',
        'coverage_score'      => 'float',
        'is_advisory'         => 'boolean',
        'summary'             => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OperationalIntelligenceSnapshot is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
