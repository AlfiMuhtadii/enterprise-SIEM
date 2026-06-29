<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningFreezeControlEvidence extends Model
{
    protected $table = 'security_hardening_freeze_control_evidence';

    protected $fillable = [
        'evidence_id', 'run_id', 'control_id', 'evidence_type',
        'evidence_path', 'evidence_summary', 'verified', 'evidence_metadata',
    ];

    protected $casts = [
        'verified'          => 'boolean',
        'evidence_metadata' => 'array',
    ];
}
