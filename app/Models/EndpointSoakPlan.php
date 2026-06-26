<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. plan_approved is ALWAYS false. NEVER UPDATE or DELETE. */
class EndpointSoakPlan extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'plan_run_id', 'domain', 'total_rules', 'tier_1_count', 'tier_2_count',
        'tier_3_count', 'tier_1_threshold', 'tier_2_threshold',
        'plan_approved', 'is_advisory', 'tenant_id', 'generated_at',
    ];

    protected $casts = [
        'plan_approved' => 'boolean',
        'is_advisory'   => 'boolean',
        'tier_1_threshold' => 'float',
        'tier_2_threshold' => 'float',
    ];
}
