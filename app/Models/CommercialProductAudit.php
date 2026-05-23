<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialProductAudit extends Model
{
    protected $table = 'commercial_product_audit';

    public const AUDIT_EVENT_TYPES = [
        'onboarding_initiated',
        'package_validated',
        'release_published',
        'support_exported',
        'readiness_assessed',
        'health_updated',
        'compatibility_checked',
    ];

    public const ACTOR_TYPES = ['operator', 'system', 'analyst'];

    protected $fillable = [
        'commercial_audit_id',
        'audit_event_type',
        'actor_type',
        'target_entity',
        'autonomous_action_taken',
        'destructive_action_taken',
        'replay_safe',
        'audit_evidence',
    ];

    protected $casts = [
        'autonomous_action_taken'  => 'boolean',
        'destructive_action_taken' => 'boolean',
        'replay_safe'              => 'boolean',
        'audit_evidence'           => 'array',
    ];
}
