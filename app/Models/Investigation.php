<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investigation extends Model
{
    use HasFactory;
    protected $fillable = [
        'investigation_id', 'title', 'description', 'state', 'severity', 'priority',
        'created_by', 'assigned_to', 'escalated_to', 'trace_id',
        'alert_ids', 'incident_ids', 'entity_ids', 'risk_score',
        'triage_at', 'resolved_at', 'closed_at', 'sla_breach_at', 'metadata',
    ];

    protected $casts = [
        'priority'    => 'integer',
        'risk_score'  => 'float',
        'alert_ids'   => 'array',
        'incident_ids'=> 'array',
        'entity_ids'  => 'array',
        'metadata'    => 'array',
        'triage_at'   => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
        'sla_breach_at'=> 'datetime',
    ];

    public const VALID_STATES = [
        'new', 'triaged', 'investigating', 'escalated',
        'contained_manual', 'resolved', 'closed', 'false_positive',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATE_TRANSITIONS = [
        'new'              => ['triaged', 'investigating', 'false_positive', 'closed'],
        'triaged'          => ['investigating', 'false_positive', 'closed'],
        'investigating'    => ['escalated', 'contained_manual', 'resolved', 'false_positive', 'closed'],
        'escalated'        => ['investigating', 'contained_manual', 'resolved', 'closed'],
        'contained_manual' => ['investigating', 'resolved', 'closed'],
        'resolved'         => ['closed', 'investigating'],
        'false_positive'   => ['closed'],
        'closed'           => [],
    ];

    public const NOTE_TYPES = [
        'general', 'triage', 'evidence', 'escalation', 'false_positive_rationale',
    ];

    public const ARTIFACT_TYPES = [
        'screenshot_metadata', 'exported_evidence', 'external_ticket',
        'related_url', 'case_reference',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InvestigationEvent::class)->orderBy('occurred_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InvestigationAssignment::class)->orderByDesc('assigned_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(InvestigationNote::class)->orderByDesc('is_pinned')->orderByDesc('created_at');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(InvestigationArtifact::class)->orderByDesc('created_at');
    }
}
