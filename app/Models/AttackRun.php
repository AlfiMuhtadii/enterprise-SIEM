<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttackRun extends Model
{
    protected $fillable = [
        'attack_type',
        'started_at',
        'ended_at',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'metadata' => 'array',
    ];
}
