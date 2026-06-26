<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. promotion_recommended ALWAYS false.
 * NEVER UPDATE or DELETE.
 */
class DomainSoakSimulation extends Model
{
    protected $table = 'domain_soak_simulations';

    public $timestamps = false;

    protected $fillable = [
        'simulation_id', 'domain', 'rules_total', 'rules_simulated',
        'events_generated', 'structural_matches', 'structural_match_rate',
        'fp_estimate_rate', 'soak_verdict', 'promotion_recommended',
        'real_soak_required', 'is_advisory', 'tenant_id', 'simulated_at',
    ];

    protected $casts = [
        'promotion_recommended' => 'boolean',
        'real_soak_required'    => 'boolean',
        'is_advisory'           => 'boolean',
    ];
}
