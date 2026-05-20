<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestigationTimelineEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id', 'investigation_id', 'session_id', 'event_category', 'event_source',
        'source_id', 'mitre_technique', 'mitre_tactic', 'description',
        'actor_entity_id', 'target_entity_id', 'confidence', 'is_advisory',
        'attributes', 'occurred_at', 'created_by', 'created_at',
    ];

    protected $casts = [
        'confidence'  => 'float',
        'is_advisory' => 'boolean',
        'attributes'  => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public const MITRE_TACTICS = [
        'initial_access', 'execution', 'persistence', 'privilege_escalation',
        'credential_access', 'discovery', 'lateral_movement', 'collection',
        'exfiltration', 'impact',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('investigation_timeline_events is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
