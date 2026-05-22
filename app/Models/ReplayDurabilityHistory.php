<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReplayDurabilityHistory extends Model
{
    protected $table = 'replay_durability_history';

    protected $fillable = [
        'history_id', 'tenant_id', 'window_type',
        'replay_success_rate_pct', 'avg_recovery_latency_seconds',
        'replay_amplification_avg', 'total_recovery_events', 'failed_recovery_events',
        'backlog_trend_slope', 'durability_acceptable', 'is_advisory', 'durability_evidence',
    ];

    protected $casts = [
        'replay_success_rate_pct'      => 'float',
        'avg_recovery_latency_seconds' => 'float',
        'replay_amplification_avg'     => 'float',
        'backlog_trend_slope'          => 'float',
        'durability_acceptable'        => 'boolean',
        'is_advisory'                  => 'boolean',
        'durability_evidence'          => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ReplayDurabilityHistory is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
