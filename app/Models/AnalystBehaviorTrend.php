<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AnalystBehaviorTrend extends Model
{
    protected $fillable = [
        'trend_id', 'analyst_id', 'tenant_id', 'window_type',
        'avg_latency_seconds', 'latency_trend_slope', 'fatigue_score',
        'escalation_quality_avg', 'suppression_usage_rate',
        'recurring_dismissal_count', 'avg_investigation_duration_minutes',
        'behavior_stable', 'is_advisory', 'behavior_evidence',
    ];

    protected $casts = [
        'avg_latency_seconds'               => 'float',
        'latency_trend_slope'               => 'float',
        'fatigue_score'                     => 'float',
        'escalation_quality_avg'            => 'float',
        'suppression_usage_rate'            => 'float',
        'avg_investigation_duration_minutes'=> 'float',
        'behavior_stable'                   => 'boolean',
        'is_advisory'                       => 'boolean',
        'behavior_evidence'                 => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AnalystBehaviorTrend is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
