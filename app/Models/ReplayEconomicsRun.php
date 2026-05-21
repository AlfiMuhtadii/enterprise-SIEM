<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReplayEconomicsRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'consumer_group', 'topic', 'replay_duration_seconds', 'replay_throughput_eps',
        'events_replayed', 'replay_backlog', 'replay_amplification_ratio', 'replay_storage_impact_mb',
        'replay_cost_estimate', 'replay_queue_pressure', 'concurrency_state', 'is_bounded',
        'triggered_by', 'created_at',
    ];

    protected $casts = [
        'replay_duration_seconds'    => 'integer',
        'replay_throughput_eps'      => 'float',
        'events_replayed'            => 'integer',
        'replay_backlog'             => 'integer',
        'replay_amplification_ratio' => 'float',
        'replay_storage_impact_mb'   => 'float',
        'replay_cost_estimate'       => 'float',
        'replay_queue_pressure'      => 'float',
        'is_bounded'                 => 'boolean',
        'created_at'                 => 'datetime',
    ];

    public const CONCURRENCY_BOUNDED           = 'bounded';
    public const CONCURRENCY_ELEVATED          = 'elevated';
    public const CONCURRENCY_APPROACHING_LIMIT = 'approaching_limit';
    public const CONCURRENCY_AT_LIMIT          = 'at_limit';

    // Safe amplification threshold — exceeding this warrants review
    public const SAFE_AMPLIFICATION_RATIO = 3.0;

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('replay_economics_runs is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
