<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttackScenarioPack extends Model
{
    protected $fillable = [
        'pack_id', 'name', 'attack_tactic', 'technique_ids', 'description',
        'fixture_event_types', 'difficulty', 'is_active', 'owner',
    ];

    protected $casts = [
        'technique_ids'      => 'array',
        'fixture_event_types'=> 'array',
        'is_active'          => 'boolean',
    ];

    public const DIFFICULTIES = ['low', 'medium', 'high', 'expert'];
}
