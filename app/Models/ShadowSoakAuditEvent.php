<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShadowSoakAuditEvent extends Model
{
    protected $table = 'shadow_soak_audit_events';

    protected $fillable = [
        'event_id', 'run_id', 'domain', 'event_type', 'actor', 'payload', 'tenant_id',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    const EVENT_TYPES = [
        'run_started', 'evidence_computed', 'gate_checked',
        'assessment_recorded', 'run_completed', 'run_failed',
    ];
}
