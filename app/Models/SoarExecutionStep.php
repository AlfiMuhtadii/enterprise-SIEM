<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoarExecutionStep extends Model
{
    protected $fillable = [
        'step_id', 'plan_id', 'step_order', 'step_name', 'action_type',
        'status', 'is_simulation_only', 'action_parameters', 'result_data', 'notes',
    ];

    protected $casts = [
        'step_order'        => 'integer',
        'is_simulation_only'=> 'boolean',
        'action_parameters' => 'array',
        'result_data'       => 'array',
    ];

    public const STATUSES = [
        'pending', 'simulation_preview', 'approved', 'completed', 'skipped', 'failed',
    ];
}
