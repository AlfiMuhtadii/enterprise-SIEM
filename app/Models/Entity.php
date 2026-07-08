<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_key',
        'tenant_id',
        'display_name',
        'first_seen_at',
        'last_seen_at',
        'observation_count',
        'risk_score',
        'risk_level',
        'risk_factors',
        'last_risk_calculated_at',
        'metadata',
    ];

    protected $casts = [
        'first_seen_at'           => 'datetime',
        'last_seen_at'            => 'datetime',
        'last_risk_calculated_at' => 'datetime',
        'observation_count'       => 'integer',
        'risk_score'              => 'float',
        'risk_factors'            => 'array',
        'metadata'                => 'array',
    ];

    public const TYPES = [
        'user', 'host', 'process', 'ip', 'domain',
        'file_hash', 'alert', 'incident', 'trace',
    ];

    public function outboundRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'source_entity_id');
    }

    public function inboundRelationships(): HasMany
    {
        return $this->hasMany(EntityRelationship::class, 'target_entity_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(EntityObservation::class);
    }

    public function riskSnapshots(): HasMany
    {
        return $this->hasMany(EntityRiskSnapshot::class);
    }
}
