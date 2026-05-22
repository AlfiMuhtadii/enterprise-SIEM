<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SensorResourceSnapshot extends Model
{
    protected $fillable = [
        'snapshot_id', 'agent_id', 'host_id', 'cpu_pct', 'memory_mb',
        'spool_size_kb', 'queue_depth', 'event_burst_rate', 'disk_pressure_kb', 'pressure_state',
    ];

    protected $casts = [
        'cpu_pct'          => 'float',
        'event_burst_rate' => 'float',
    ];

    public const PRESSURE_STATES = ['normal', 'elevated', 'high', 'critical'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('SensorResourceSnapshot is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
