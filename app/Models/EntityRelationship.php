<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityRelationship extends Model
{
    protected $fillable = [
        'source_entity_id',
        'target_entity_id',
        'relationship_type',
        'first_seen_at',
        'last_seen_at',
        'observation_count',
        'confidence',
        'relationship_origin',
        'source_table',
        'source_event_id',
        'trace_id',
        'metadata',
    ];

    protected $casts = [
        'first_seen_at'     => 'datetime',
        'last_seen_at'      => 'datetime',
        'observation_count' => 'integer',
        'confidence'        => 'float',
        'metadata'          => 'array',
    ];

    public const RELATIONSHIP_TYPES = [
        'user_logged_into_host',
        'process_started_by_user',
        'process_connected_to_ip',
        'process_queried_domain',
        'alert_involves_entity',
        'incident_contains_alert',
        'trace_contains_entity',
    ];

    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'source_entity_id');
    }

    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'target_entity_id');
    }
}
