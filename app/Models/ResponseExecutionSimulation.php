<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseExecutionSimulation extends Model
{
    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'simulation_id', 'execution_id', 'simulated_by',
        'blast_radius_entities', 'impacted_services',
        'rollback_available', 'estimated_impact_score',
        'simulation_notes', 'warnings', 'trace_id',
    ];

    protected $casts = [
        'blast_radius_entities' => 'array',
        'impacted_services'     => 'array',
        'rollback_available'    => 'boolean',
        'warnings'              => 'array',
        'estimated_impact_score'=> 'float',
        'created_at'            => 'datetime',
    ];

    public static function generateSimulationId(): string
    {
        $year = (int) date('Y');
        $max  = static::whereYear('created_at', $year)->max('id') ?? 0;
        return sprintf('SIM-%d-%05d', $year, $max + 1);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(ResponseExecution::class, 'execution_id');
    }

    public function simulator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'simulated_by');
    }
}
