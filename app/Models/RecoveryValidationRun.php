<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecoveryValidationRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'scenario', 'triggered_by', 'status',
        'total_checks', 'passed_checks', 'failed_checks',
        'trace_propagation_ok', 'replay_idempotency_ok', 'worker_stale_detection_ok',
        'check_results', 'failure_summary', 'trace_id', 'completed_at', 'created_at',
    ];

    protected $casts = [
        'total_checks'             => 'integer',
        'passed_checks'            => 'integer',
        'failed_checks'            => 'integer',
        'trace_propagation_ok'     => 'boolean',
        'replay_idempotency_ok'    => 'boolean',
        'worker_stale_detection_ok'=> 'boolean',
        'check_results'            => 'array',
        'completed_at'             => 'datetime',
        'created_at'               => 'datetime',
    ];

    public const SCENARIOS = [
        'service_restart', 'replay_resume', 'dlq_bounded',
        'idempotency_check', 'trace_propagation',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_PASSED  = 'passed';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_WARNING = 'warning';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('recovery_validation_runs is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
