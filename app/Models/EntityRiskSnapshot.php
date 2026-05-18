<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityRiskSnapshot extends Model
{
    protected $fillable = [
        'entity_id',
        'entity_type',
        'entity_key',
        'risk_score',
        'risk_level',
        'risk_factors',
        'alert_ids',
        'incident_ids',
        'trace_ids',
        'calculated_at',
    ];

    protected $casts = [
        'risk_score'    => 'float',
        'risk_factors'  => 'array',
        'alert_ids'     => 'array',
        'incident_ids'  => 'array',
        'trace_ids'     => 'array',
        'calculated_at' => 'datetime',
    ];

    public const LEVELS = ['low', 'medium', 'high', 'critical'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
