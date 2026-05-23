<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialReleaseHistory extends Model
{
    protected $table = 'commercial_release_history';

    public const RELEASE_STAGES = ['alpha', 'beta', 'rc', 'ga', 'lts'];

    public const RELEASE_STATES = ['drafted', 'validated', 'approved', 'published', 'deprecated'];

    protected $fillable = [
        'release_id',
        'release_version',
        'release_stage',
        'release_state',
        'manifest_hash',
        'migration_compatible',
        'rollback_compatible',
        'self_approve_blocked',
        'replay_safe',
        'release_evidence',
    ];

    protected $casts = [
        'migration_compatible'  => 'boolean',
        'rollback_compatible'   => 'boolean',
        'self_approve_blocked'  => 'boolean',
        'replay_safe'           => 'boolean',
        'release_evidence'      => 'array',
    ];
}
