<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestigationGraphNode extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'node_id', 'investigation_id', 'session_id', 'node_type', 'entity_id',
        'label', 'source_domain', 'observable_value', 'risk_score',
        'is_pivot_origin', 'is_advisory', 'attributes', 'created_by', 'created_at',
    ];

    protected $casts = [
        'risk_score'      => 'float',
        'is_pivot_origin' => 'boolean',
        'is_advisory'     => 'boolean',
        'attributes'      => 'array',
        'created_at'      => 'datetime',
    ];

    public const NODE_TYPES = [
        'user', 'host', 'process', 'ip', 'domain',
        'container', 'alert', 'incident', 'technique', 'artifact',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('investigation_graph_nodes is append-safe — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
