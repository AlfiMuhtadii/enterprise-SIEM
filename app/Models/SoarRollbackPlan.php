<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoarRollbackPlan extends Model
{
    protected $fillable = [
        'rollback_id', 'plan_id', 'rollback_steps', 'rollback_scope',
        'rollback_readiness_state', 'rollback_dependencies', 'simulation_validated',
        'approval_required', 'created_by',
    ];

    protected $casts = [
        'rollback_steps'       => 'array',
        'rollback_dependencies'=> 'array',
        'simulation_validated' => 'boolean',
        'approval_required'    => 'boolean',
    ];

    public const READINESS_STATES = ['not_ready', 'ready', 'validated', 'executed'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
