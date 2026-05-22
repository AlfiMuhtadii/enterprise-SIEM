<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AnalystWorkloadSnapshot extends Model
{
    protected $fillable = [
        'snapshot_id', 'analyst_id', 'tenant_id', 'shift_id',
        'open_investigations', 'pending_acknowledgments', 'escalation_queue_depth',
        'avg_acknowledgment_latency_seconds', 'investigations_completed_last_8h',
        'workload_score', 'overload_indicator', 'is_advisory', 'metadata',
    ];

    protected $casts = [
        'avg_acknowledgment_latency_seconds' => 'float',
        'workload_score'                     => 'float',
        'overload_indicator'                 => 'boolean',
        'is_advisory'                        => 'boolean',
        'metadata'                           => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AnalystWorkloadSnapshot is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
