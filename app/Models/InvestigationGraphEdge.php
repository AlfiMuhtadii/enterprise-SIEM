<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestigationGraphEdge extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'edge_id', 'investigation_id', 'session_id', 'from_node_id', 'to_node_id',
        'relationship_type', 'evidence_source', 'evidence_id', 'confidence',
        'is_advisory', 'attributes', 'created_by', 'created_at',
    ];

    protected $casts = [
        'confidence'  => 'float',
        'is_advisory' => 'boolean',
        'attributes'  => 'array',
        'created_at'  => 'datetime',
    ];

    public const RELATIONSHIP_TYPES = [
        'spawned', 'connected_to', 'authenticated_as', 'observed_on',
        'triggered', 'linked_to', 'pivoted_from',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('investigation_graph_edges is append-safe — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
