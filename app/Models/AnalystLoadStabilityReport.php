<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AnalystLoadStabilityReport extends Model
{
    protected $fillable = [
        'report_id', 'run_id', 'tenant_id', 'alert_throughput_per_hour',
        'avg_acknowledgment_latency_seconds', 'escalation_backlog', 'fatigue_detected',
        'repeated_dismissal_count', 'avg_investigation_duration_minutes',
        'queue_growth_rate', 'workload_stable', 'is_advisory', 'stability_evidence',
    ];

    protected $casts = [
        'alert_throughput_per_hour'          => 'float',
        'avg_acknowledgment_latency_seconds' => 'float',
        'avg_investigation_duration_minutes' => 'float',
        'queue_growth_rate'                  => 'float',
        'fatigue_detected'                   => 'boolean',
        'workload_stable'                    => 'boolean',
        'is_advisory'                        => 'boolean',
        'stability_evidence'                 => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AnalystLoadStabilityReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
