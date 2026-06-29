<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningFreezeAuditEvent extends Model
{
    protected $table = 'security_hardening_freeze_audit_events';

    protected $fillable = [
        'event_id', 'run_id', 'event_type', 'actor',
        'detail', 'event_metadata', 'occurred_at',
    ];

    protected $casts = [
        'event_metadata' => 'array',
        'occurred_at'    => 'datetime',
    ];
}
