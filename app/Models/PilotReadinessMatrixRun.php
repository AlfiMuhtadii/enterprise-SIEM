<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PilotReadinessMatrixRun extends Model
{
    protected $fillable = [
        'matrix_run_id', 'tenant_id', 'operator_id', 'pilot_scope',
        'status', 'total_gates', 'gates_passed', 'gates_warned', 'gates_failed',
        'required_gates_pass', 'matrix_score', 'finalized_at', 'approved_by',
        'reviewer_notes', 'is_advisory', 'autonomous_promotion',
    ];

    protected $casts = [
        'pilot_scope'         => 'array',
        'required_gates_pass' => 'boolean',
        'matrix_score'        => 'float',
        'is_advisory'         => 'boolean',
        'autonomous_promotion' => 'boolean',
        'finalized_at'        => 'datetime',
    ];

    public const STATUSES = ['initiated', 'evaluating', 'passed', 'failed', 'rejected'];

    public function delete(): bool|null
    {
        throw new LogicException('pilot_readiness_matrix_runs records cannot be deleted.');
    }

    public function forceDelete(): bool|null
    {
        throw new LogicException('pilot_readiness_matrix_runs records cannot be deleted.');
    }
}
