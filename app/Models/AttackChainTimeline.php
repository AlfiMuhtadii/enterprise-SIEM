<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttackChainTimeline extends Model
{
    protected $fillable = [
        'timeline_id', 'chain_id', 'tactic', 'technique_id', 'host_id',
        'actor', 'event_type', 'evidence_snapshot', 'sequence_index', 'occurred_at',
    ];

    protected $casts = [
        'evidence_snapshot' => 'array',
        'occurred_at'       => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('AttackChainTimeline is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
