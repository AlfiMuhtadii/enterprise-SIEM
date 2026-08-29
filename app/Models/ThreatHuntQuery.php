<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreatHuntQuery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'hunt_id', 'query_domain', 'query_filters',
        'time_range_start', 'time_range_end', 'max_results', 'tenant_id',
    ];

    protected $casts = [
        'query_filters' => 'array',
        'time_range_start' => 'datetime',
        'time_range_end' => 'datetime',
    ];

    public function hunt(): BelongsTo
    {
        return $this->belongsTo(ThreatHunt::class, 'hunt_id');
    }
}
