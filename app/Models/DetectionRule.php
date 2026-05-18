<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DetectionRule extends Model
{
    protected $fillable = [
        'rule_id', 'stage', 'version', 'owner',
        'replay_validated', 'replay_validated_at',
        'suppression_active', 'suppression_reason', 'suppression_expires_at',
        'false_positive_rate', 'promotion_approved_by', 'promotion_approved_at',
        'gate_results', 'checklist_state',
    ];

    protected $casts = [
        'replay_validated'      => 'boolean',
        'replay_validated_at'   => 'datetime',
        'suppression_active'    => 'boolean',
        'suppression_expires_at'=> 'datetime',
        'promotion_approved_at' => 'datetime',
        'gate_results'          => 'array',
        'checklist_state'       => 'array',
    ];

    public function notes(): HasMany
    {
        return $this->hasMany(DetectionRuleNote::class, 'rule_id', 'rule_id');
    }

    public function stageBadgeClass(): string
    {
        return match ($this->stage) {
            'staged_active' => 'border-green-400/40 bg-green-500/10 text-green-200',
            'shadow'        => 'border-yellow-400/40 bg-yellow-500/10 text-yellow-200',
            'draft'         => 'border-cyan-200/25 bg-cyan-500/10 text-cyan-200',
            'deprecated'    => 'border-red-400/30 bg-red-500/10 text-red-200/60',
            default         => 'border-cyan-200/20 bg-black/20 text-cyan-100/50',
        };
    }

    public function nextStage(): ?string
    {
        return match ($this->stage) {
            'draft'         => 'shadow',
            'shadow'        => 'staged_active',
            'staged_active' => 'deprecated',
            default         => null,
        };
    }
}
