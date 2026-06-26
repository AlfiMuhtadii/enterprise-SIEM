<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ENTERPRISE-052: Mutable pilot tenant profile. Safe to update; never delete.
 */
class PilotTenantProfile extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'tenant_name', 'tenant_type', 'status',
        'strict_mode_compatible', 'null_backfill_completed',
        'member_count', 'alert_count', 'incident_count',
        'is_advisory', 'onboarded_by',
        'onboarded_at', 'last_validated_at',
    ];

    protected $casts = [
        'strict_mode_compatible'  => 'boolean',
        'null_backfill_completed' => 'boolean',
        'is_advisory'             => 'boolean',
        'onboarded_at'            => 'datetime',
        'last_validated_at'       => 'datetime',
    ];
}
