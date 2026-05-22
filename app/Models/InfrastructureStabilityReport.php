<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InfrastructureStabilityReport extends Model
{
    public const VERDICTS = ['stable', 'monitoring', 'degrading', 'critical'];

    protected $fillable = [
        'report_id', 'tenant_id', 'window_type',
        'avg_cpu_pct', 'avg_memory_growth_mb', 'avg_storage_pressure_pct', 'avg_query_latency_ms',
        'cpu_trend_slope', 'memory_trend_slope', 'storage_trend_slope',
        'stability_verdict', 'is_advisory', 'stability_evidence',
    ];

    protected $casts = [
        'avg_cpu_pct'             => 'float',
        'avg_memory_growth_mb'    => 'float',
        'avg_storage_pressure_pct'=> 'float',
        'avg_query_latency_ms'    => 'float',
        'cpu_trend_slope'         => 'float',
        'memory_trend_slope'      => 'float',
        'storage_trend_slope'     => 'float',
        'is_advisory'             => 'boolean',
        'stability_evidence'      => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('InfrastructureStabilityReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
