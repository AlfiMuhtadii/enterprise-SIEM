<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoundedFailureScenario extends Model
{
    // MUTABLE — scenario catalog with governance controls
    protected $fillable = [
        'scenario_key', 'name', 'component', 'max_duration_seconds',
        'enabled', 'requires_approval', 'destructive',
        'allowed_in_environments', 'description',
    ];

    protected $casts = [
        'enabled'                => 'boolean',
        'requires_approval'      => 'boolean',
        'destructive'            => 'boolean',
        'allowed_in_environments'=> 'array',
    ];
}
