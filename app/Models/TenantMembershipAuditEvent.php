<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantMembershipAuditEvent extends Model
{
    protected $table = 'tenant_membership_audit_events';

    // Append-only — no updated_at column; do not update or delete rows.
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'membership_id',
        'user_id',
        'tenant_id',
        'event_type',
        'actor_id',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    const EVENT_TYPES = ['granted', 'revoked', 're_granted'];
}
