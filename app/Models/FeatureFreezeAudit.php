<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFreezeAudit extends Model
{
    protected $table = 'feature_freeze_audit';

    protected $fillable = [
        'freeze_audit_id', 'freeze_event', 'freeze_scope', 'subsystem',
        'scope_expansion_blocked', 'hotfix_allowed', 'autonomous_scope_change',
        'replay_safe', 'rc_version', 'freeze_evidence',
    ];

    protected $casts = [
        'scope_expansion_blocked'  => 'boolean',
        'hotfix_allowed'           => 'boolean',
        'autonomous_scope_change'  => 'boolean',
        'replay_safe'              => 'boolean',
        'freeze_evidence'          => 'array',
    ];

    public const FREEZE_EVENTS  = ['freeze_initiated', 'hotfix_approved', 'scope_change_rejected', 'freeze_lifted'];
    public const FREEZE_SCOPES  = ['full', 'subsystem_specific', 'hotfix_only'];
}
