<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamConsumerLagSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'consumer_group', 'topic', 'partition', 'worker_id',
        'lag_count', 'lag_age_seconds', 'poison_message_count', 'idempotency_violations',
        'retry_budget_remaining', 'trend', 'pressure_state', 'dlq_growing',
        'trace_id', 'created_at',
    ];

    protected $casts = [
        'lag_count'              => 'integer',
        'lag_age_seconds'        => 'integer',
        'poison_message_count'   => 'integer',
        'idempotency_violations' => 'integer',
        'retry_budget_remaining' => 'integer',
        'dlq_growing'            => 'boolean',
        'created_at'             => 'datetime',
    ];

    public const TRENDS = ['increasing', 'stable', 'decreasing'];
    public const PRESSURE_NORMAL   = 'normal';
    public const PRESSURE_WARNING  = 'warning';
    public const PRESSURE_CRITICAL = 'critical';

    public const LAG_WARNING_COUNT  = 1000;
    public const LAG_CRITICAL_COUNT = 10000;

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('stream_consumer_lag_snapshots is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
