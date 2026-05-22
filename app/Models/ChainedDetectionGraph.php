<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChainedDetectionGraph extends Model
{
    protected $fillable = [
        'graph_id', 'chain_type', 'node_sequence', 'tactic_sequence',
        'hop_count', 'chain_confidence', 'host_id', 'actor', 'status',
        'evidence_links', 'triggered_by',
    ];

    protected $casts = [
        'node_sequence'    => 'array',
        'tactic_sequence'  => 'array',
        'evidence_links'   => 'array',
        'chain_confidence' => 'float',
    ];

    public const CHAIN_TYPES = [
        'credential_to_persistence', 'lateral_movement', 'multi_stage_attack',
        'defense_evasion_chain', 'cloud_to_endpoint', 'container_escape_chain',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('ChainedDetectionGraph is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
