<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningFreezeRemediationGuidance extends Model
{
    protected $table = 'security_hardening_freeze_remediation_guidance';

    protected $fillable = [
        'guidance_id', 'run_id', 'control_id', 'priority',
        'guidance_text', 'autonomous_remediation', 'guidance_metadata',
    ];

    protected $casts = [
        'autonomous_remediation' => 'boolean',
        'guidance_metadata'      => 'array',
    ];
}
