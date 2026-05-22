<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TacticProgressionSnapshot extends Model
{
    protected $fillable = [
        'snapshot_id', 'host_id', 'actor', 'observed_tactics', 'observed_techniques',
        'tactic_count', 'multi_stage', 'progression_score', 'detection_scope',
    ];

    protected $casts = [
        'observed_tactics'    => 'array',
        'observed_techniques' => 'array',
        'multi_stage'         => 'boolean',
        'progression_score'   => 'float',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('TacticProgressionSnapshot is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
