<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResilienceMetric extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'run_id', 'metric_name', 'metric_value', 'unit',
        'threshold', 'within_threshold', 'recorded_at',
    ];

    protected $casts = [
        'metric_value'     => 'float',
        'threshold'        => 'float',
        'within_threshold' => 'boolean',
        'recorded_at'      => 'datetime',
        'created_at'       => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ResilienceRun::class, 'run_id');
    }
}
