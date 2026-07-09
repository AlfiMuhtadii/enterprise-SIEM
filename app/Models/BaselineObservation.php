<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only individual observed values that feed into behavioral baselines.
 * Never updated after insert. Replay-safe by design.
 */
class BaselineObservation extends Model
{
    public $timestamps = false;   // only created_at, no updated_at

    protected $table = 'baseline_observations';

    protected $fillable = [
        'observation_id',
        'entity_key',
        'entity_type',
        'tenant_id',
        'dimension',
        'observed_value',
        'source_table',
        'source_event_id',
        'trace_id',
        'context',
        'advisory_only',
        'observed_at',
        'created_at',
    ];

    protected $casts = [
        'observed_value' => 'float',
        'context'        => 'array',
        'advisory_only'  => 'boolean',
        'observed_at'    => 'datetime',
        'created_at'     => 'datetime',
    ];
}
