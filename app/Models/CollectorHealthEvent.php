<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectorHealthEvent extends Model
{
    protected $fillable = [
        'event_id', 'agent_id', 'host_id', 'health_state', 'previous_state',
        'event_type', 'reason', 'operator_notified',
    ];

    protected $casts = ['operator_notified' => 'boolean'];

    public const HEALTH_STATES = ['healthy', 'degraded', 'restarting', 'stalled', 'disconnected', 'recovering'];

    public const EVENT_TYPES = [
        'state_transition', 'crash', 'restart', 'disablement',
        'reconnect', 'recovery', 'heartbeat_timeout',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('CollectorHealthEvent is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
