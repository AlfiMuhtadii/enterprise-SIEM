<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ServiceLifecycleAudit extends Model
{
    protected $table = 'service_lifecycle_audit';

    public const LIFECYCLE_EVENTS = [
        'startup', 'shutdown', 'restart', 'degraded', 'recovered', 'drift_detected',
    ];

    protected $fillable = [
        'lifecycle_id', 'service_name', 'lifecycle_event', 'previous_state',
        'current_state', 'dependency_satisfied', 'drift_detected',
        'is_advisory', 'lifecycle_evidence',
    ];

    protected $casts = [
        'dependency_satisfied' => 'boolean',
        'drift_detected'       => 'boolean',
        'is_advisory'          => 'boolean',
        'lifecycle_evidence'   => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('ServiceLifecycleAudit is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
