<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityHardeningFreezeGateSnapshot extends Model
{
    protected $table = 'security_hardening_freeze_gate_snapshots';

    protected $fillable = [
        'snapshot_id', 'run_id', 'gate_name', 'gate_passed',
        'gate_score', 'gate_state', 'autonomous_waiver', 'gate_detail',
    ];

    protected $casts = [
        'gate_passed'       => 'boolean',
        'autonomous_waiver' => 'boolean',
        'gate_score'        => 'float',
        'gate_detail'       => 'array',
    ];
}
