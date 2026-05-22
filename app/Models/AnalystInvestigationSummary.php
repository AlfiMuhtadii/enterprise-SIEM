<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AnalystInvestigationSummary extends Model
{
    public const VERDICTS = ['confirmed', 'dismissed', 'needs_review', 'escalated'];

    protected $fillable = [
        'summary_id', 'tenant_id', 'analyst_id', 'investigation_id',
        'attack_tactic', 'attack_technique', 'evidence_count', 'chained_count',
        'confidence_score', 'verdict', 'replay_safe', 'is_advisory', 'evidence_links',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'replay_safe'      => 'boolean',
        'is_advisory'      => 'boolean',
        'evidence_links'   => 'array',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('AnalystInvestigationSummary is append-only and cannot be updated.');
        }
        return parent::save($options);
    }
}
