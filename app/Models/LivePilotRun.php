<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LivePilotRun extends Model
{
    public const STATUSES = ['pending', 'active', 'completed', 'aborted', 'rolled_back'];
    public const OBSERVATION_WINDOWS = [24, 48, 72];

    protected $fillable = [
        'run_id', 'tenant_id', 'pilot_name', 'target_endpoint_count',
        'enrolled_endpoint_count', 'status', 'activation_approved', 'approved_by',
        'activated_at', 'completed_at', 'observation_window_hours',
        'rollback_ready', 'is_advisory', 'summary',
    ];

    protected $casts = [
        'activation_approved' => 'boolean',
        'rollback_ready'      => 'boolean',
        'is_advisory'         => 'boolean',
        'activated_at'        => 'datetime',
        'completed_at'        => 'datetime',
        'summary'             => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('LivePilotRun is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
