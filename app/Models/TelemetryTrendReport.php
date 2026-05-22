<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TelemetryTrendReport extends Model
{
    public const TREND_VERDICTS = ['stable', 'degrading', 'improving', 'critical'];

    protected $fillable = [
        'report_id', 'tenant_id', 'window_type', 'continuity_trend_slope',
        'queue_lag_trend_slope', 'duplicate_rate_trend', 'replay_backlog_trend_slope',
        'telemetry_gap_accumulation', 'storage_growth_rate_gb_per_day',
        'trend_verdict', 'replay_safe', 'is_advisory', 'trend_data',
    ];

    protected $casts = [
        'continuity_trend_slope'         => 'float',
        'queue_lag_trend_slope'          => 'float',
        'duplicate_rate_trend'           => 'float',
        'replay_backlog_trend_slope'     => 'float',
        'telemetry_gap_accumulation'     => 'float',
        'storage_growth_rate_gb_per_day' => 'float',
        'replay_safe'                    => 'boolean',
        'is_advisory'                    => 'boolean',
        'trend_data'                     => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('TelemetryTrendReport is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
