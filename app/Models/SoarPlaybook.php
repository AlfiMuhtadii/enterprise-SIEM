<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoarPlaybook extends Model
{
    protected $fillable = [
        'playbook_id', 'name', 'description', 'status', 'version',
        'execution_scope', 'allowed_actions', 'approval_requirements',
        'rollback_reference', 'simulation_required', 'dual_approval_required',
        'created_by', 'metadata',
    ];

    protected $casts = [
        'allowed_actions'      => 'array',
        'approval_requirements'=> 'array',
        'simulation_required'  => 'boolean',
        'dual_approval_required'=> 'boolean',
        'metadata'             => 'array',
    ];

    public const STATUSES = ['draft', 'active', 'deprecated'];

    public const SCOPES = ['advisory', 'simulation_only'];

    // All Phase 1 allowed playbook actions — simulation-mode where applicable
    public const ALLOWED_PHASE1_ACTIONS = [
        'notify_analyst',
        'create_incident',
        'create_ticket',
        'enrich_ioc',
        'request_approval',
        'create_watchlist_entry',
        'simulate_endpoint_isolation',
        'simulate_credential_revocation',
        'simulate_firewall_block',
        'simulate_token_revocation',
    ];

    // Simulation-only actions — NEVER execute live
    public const SIMULATION_ONLY_ACTIONS = [
        'simulate_endpoint_isolation',
        'simulate_credential_revocation',
        'simulate_firewall_block',
        'simulate_token_revocation',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SoarPlaybookVersion::class, 'playbook_id', 'playbook_id');
    }
}
