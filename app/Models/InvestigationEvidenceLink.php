<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestigationEvidenceLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'link_id', 'investigation_id', 'session_id', 'node_id', 'edge_id',
        'evidence_type', 'evidence_id', 'evidence_table', 'rationale',
        'is_advisory', 'created_by', 'created_at',
    ];

    protected $casts = [
        'is_advisory' => 'boolean',
        'created_at'  => 'datetime',
    ];

    public const EVIDENCE_TYPES = [
        'alert', 'incident', 'entity_observation', 'hunt_result',
        'cross_domain_correlation', 'stream_event',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('investigation_evidence_links is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
