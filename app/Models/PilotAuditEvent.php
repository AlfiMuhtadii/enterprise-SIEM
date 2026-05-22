<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotAuditEvent extends Model
{
    public const EVENT_TYPES = [
        'onboarding_started', 'onboarding_approved', 'health_check',
        'metric_snapshot', 'rollback_triggered', 'operator_ack',
        'pilot_completed', 'pilot_aborted',
    ];

    protected $fillable = [
        'event_id', 'run_id', 'tenant_id', 'event_type',
        'actor_id', 'description', 'payload', 'is_advisory',
    ];

    protected $casts = [
        'payload'    => 'array',
        'is_advisory'=> 'boolean',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('PilotAuditEvent is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
