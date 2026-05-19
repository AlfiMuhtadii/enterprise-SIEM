<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: advisory-only tamper visibility findings.
 * No enforcement actions are executed. All findings are explainable and evidence-linked.
 * Never updated after insert.
 */
class EndpointTamperEvent extends Model
{
    public $timestamps = false;

    // Tamper indicator types — detection only, no autonomous response
    public const TYPE_HEARTBEAT_GAP          = 'heartbeat_gap';
    public const TYPE_CONFIG_MISMATCH        = 'config_mismatch';
    public const TYPE_BINARY_HASH_MISMATCH   = 'binary_hash_mismatch';
    public const TYPE_SUSPICIOUS_UNINSTALL   = 'suspicious_uninstall';
    public const TYPE_TELEMETRY_INTERRUPTION = 'telemetry_interruption';
    public const TYPE_POLICY_DRIFT           = 'policy_drift';
    public const TYPE_DISABLED_COLLECTOR     = 'disabled_collector';
    public const TYPE_AGENT_STOPPED          = 'agent_stopped';

    public const TAMPER_TYPES = [
        self::TYPE_HEARTBEAT_GAP,
        self::TYPE_CONFIG_MISMATCH,
        self::TYPE_BINARY_HASH_MISMATCH,
        self::TYPE_SUSPICIOUS_UNINSTALL,
        self::TYPE_TELEMETRY_INTERRUPTION,
        self::TYPE_POLICY_DRIFT,
        self::TYPE_DISABLED_COLLECTOR,
        self::TYPE_AGENT_STOPPED,
    ];

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_MEDIUM   = 'medium';
    public const SEVERITY_LOW      = 'low';

    protected $table = 'endpoint_tamper_events';

    protected $fillable = [
        'tamper_id', 'agent_id', 'tamper_type', 'severity',
        'description', 'evidence', 'confidence', 'is_advisory',
        'acknowledged', 'trace_id', 'detected_at', 'created_at',
    ];

    protected $casts = [
        'evidence'    => 'array',
        'confidence'  => 'float',
        'is_advisory' => 'boolean',
        'acknowledged'=> 'boolean',
        'detected_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }
}
