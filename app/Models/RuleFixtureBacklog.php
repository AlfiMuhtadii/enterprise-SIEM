<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ENTERPRISE-050: Mutable tracking record for per-rule fixture/evidence debt.
 * Safe to upsert by rule_id; never delete.
 */
class RuleFixtureBacklog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rule_id', 'domain', 'title', 'status', 'confidence',
        'confidence_source', 'has_replay_fixture', 'has_validation_evidence',
        'fixture_path', 'priority_tier', 'batch_phase', 'notes',
        'is_advisory', 'tenant_id', 'last_inventoried_at',
    ];

    protected $casts = [
        'confidence'             => 'float',
        'has_replay_fixture'     => 'boolean',
        'has_validation_evidence'=> 'boolean',
        'is_advisory'            => 'boolean',
        'last_inventoried_at'    => 'datetime',
    ];
}
