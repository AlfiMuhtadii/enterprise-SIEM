<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityObservation extends Model
{
    protected $fillable = [
        'entity_id',
        'observation_type',
        'source_table',
        'source_event_id',
        'trace_id',
        'observed_at',
        'context',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
        'context'     => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
