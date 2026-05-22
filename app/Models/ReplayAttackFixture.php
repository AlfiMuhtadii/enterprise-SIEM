<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReplayAttackFixture extends Model
{
    protected $fillable = [
        'fixture_id', 'name', 'attack_tactic', 'technique_id',
        'fixture_type', 'event_sequence', 'is_active', 'owner',
    ];

    protected $casts = [
        'event_sequence' => 'array',
        'is_active'      => 'boolean',
    ];

    public const FIXTURE_TYPES = ['benign', 'malicious', 'ambiguous', 'partial'];
}
