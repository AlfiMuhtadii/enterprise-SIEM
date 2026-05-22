<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdversarialValidationRun extends Model
{
    protected $fillable = [
        'run_id', 'scenario_pack_id', 'scenario_name', 'attack_tactic', 'attack_technique',
        'verdict', 'detected', 'false_positive_free', 'detection_confidence',
        'replay_event_count', 'matched_rules', 'matched_rule_ids', 'validation_details',
        'triggered_by',
    ];

    protected $casts = [
        'detected'            => 'boolean',
        'false_positive_free' => 'boolean',
        'detection_confidence'=> 'float',
        'matched_rule_ids'    => 'array',
        'validation_details'  => 'array',
    ];

    public const VERDICT_PASS    = 'pass';
    public const VERDICT_FAIL    = 'fail';
    public const VERDICT_PARTIAL = 'partial';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('AdversarialValidationRun is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
