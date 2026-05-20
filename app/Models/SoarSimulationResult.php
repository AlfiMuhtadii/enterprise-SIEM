<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoarSimulationResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sim_result_id', 'plan_id', 'playbook_id', 'blast_radius_score',
        'estimated_affected_entities', 'affected_entity_ids', 'step_previews',
        'rollback_ready', 'approval_required', 'warnings', 'is_advisory',
        'simulated_by', 'created_at',
    ];

    protected $casts = [
        'blast_radius_score'          => 'float',
        'estimated_affected_entities' => 'integer',
        'affected_entity_ids'         => 'array',
        'step_previews'               => 'array',
        'rollback_ready'              => 'boolean',
        'approval_required'           => 'boolean',
        'warnings'                    => 'array',
        'is_advisory'                 => 'boolean',
        'created_at'                  => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('soar_simulation_results is append-only — no updates allowed');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
        }
        return parent::save($options);
    }
}
