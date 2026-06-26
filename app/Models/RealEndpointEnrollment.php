<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ENTERPRISE-053: Append-only enrollment record for a real OS-verified endpoint.
 * NEVER UPDATE or DELETE rows.
 */
class RealEndpointEnrollment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'enrollment_id', 'enrollment_token', 'hostname',
        'os_platform', 'os_version', 'agent_version',
        'tenant_id', 'heartbeat_received', 'snapshot_received',
        'process_count', 'persistence_count', 'collector_summary',
        'is_real', 'is_advisory',
        'enrolled_at', 'last_heartbeat_at',
    ];

    protected $casts = [
        'heartbeat_received' => 'boolean',
        'snapshot_received'  => 'boolean',
        'collector_summary'  => 'array',
        'is_real'            => 'boolean',
        'is_advisory'        => 'boolean',
        'enrolled_at'        => 'datetime',
        'last_heartbeat_at'  => 'datetime',
    ];
}
