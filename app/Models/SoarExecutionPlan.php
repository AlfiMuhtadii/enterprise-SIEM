<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SoarExecutionPlan extends Model
{
    protected $fillable = [
        'plan_id', 'playbook_id', 'playbook_version_id', 'name', 'status',
        'target_investigation_id', 'target_entity_id', 'blast_radius_score',
        'simulation_required', 'simulation_completed', 'dual_approval_required',
        'rollback_ready', 'created_by', 'approver_1_id', 'approver_2_id',
        'approved_at', 'completed_at', 'metadata',
    ];

    protected $casts = [
        'blast_radius_score'    => 'float',
        'simulation_required'   => 'boolean',
        'simulation_completed'  => 'boolean',
        'dual_approval_required'=> 'boolean',
        'rollback_ready'        => 'boolean',
        'metadata'              => 'array',
        'approved_at'           => 'datetime',
        'completed_at'          => 'datetime',
    ];

    public const STATUSES = [
        'draft', 'simulation_pending', 'simulated', 'approval_pending',
        'approved', 'executing', 'completed', 'rolled_back', 'cancelled', 'failed',
    ];

    public const TERMINAL_STATUSES = ['completed', 'rolled_back', 'cancelled', 'failed'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SoarExecutionStep::class, 'plan_id', 'plan_id')->orderBy('step_order');
    }

    public function rollbackPlan(): HasOne
    {
        return $this->hasOne(SoarRollbackPlan::class, 'plan_id', 'plan_id');
    }

    public function latestSimulation(): HasOne
    {
        return $this->hasOne(SoarSimulationResult::class, 'plan_id', 'plan_id')->latestOfMany('created_at');
    }
}
