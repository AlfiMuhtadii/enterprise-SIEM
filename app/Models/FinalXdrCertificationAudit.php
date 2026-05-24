<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinalXdrCertificationAudit extends Model
{
    protected $table = 'final_xdr_certification_audit';

    protected $fillable = [
        'cert_audit_id', 'audit_event_type', 'actor_type',
        'certification_id', 'autonomous_action_taken',
        'destructive_action_taken', 'replay_safe', 'audit_evidence',
    ];

    protected $casts = [
        'autonomous_action_taken'  => 'boolean',
        'destructive_action_taken' => 'boolean',
        'replay_safe'              => 'boolean',
        'audit_evidence'           => 'array',
    ];

    public const AUDIT_EVENT_TYPES = [
        'certification_initiated', 'gate_passed', 'gate_failed',
        'risk_escalated', 'report_signed', 'go_live_approved', 'rollback_triggered',
    ];

    public const ACTOR_TYPES = ['operator', 'analyst', 'system'];
}
