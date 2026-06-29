<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningFreezeCertificationRequest extends Model
{
    protected $table = 'security_hardening_freeze_certification_requests';

    protected $fillable = [
        'request_id', 'run_id', 'requested_by', 'request_state',
        'self_approve_blocked', 'autonomous_approval', 'advisory_only',
        'justification', 'request_metadata',
    ];

    protected $casts = [
        'self_approve_blocked' => 'boolean',
        'autonomous_approval'  => 'boolean',
        'advisory_only'        => 'boolean',
        'request_metadata'     => 'array',
    ];
}
