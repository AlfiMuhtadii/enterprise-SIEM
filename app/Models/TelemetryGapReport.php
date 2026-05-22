<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryGapReport extends Model
{
    protected $fillable = [
        'report_id', 'agent_id', 'host_id', 'gap_duration_seconds', 'estimated_lost_events',
        'gap_reason', 'recovered', 'replay_attempted', 'gap_started_at', 'gap_ended_at',
    ];

    protected $casts = [
        'recovered'       => 'boolean',
        'replay_attempted'=> 'boolean',
        'gap_started_at'  => 'datetime',
        'gap_ended_at'    => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('TelemetryGapReport is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
