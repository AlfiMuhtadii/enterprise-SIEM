<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a privilege escalation indicator.
 * Advisory-only — never triggers enforcement or host isolation.
 */
class EndpointPrivilegeEscalation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'escalation_id', 'agent_id', 'process_name', 'pid',
        'original_uid', 'escalated_uid', 'original_user', 'escalated_user',
        'escalation_type', 'command_line', 'telemetry_source',
        'host_id', 'trace_id', 'is_advisory', 'confidence',
        'occurred_at', 'created_at',
    ];

    protected $casts = [
        'is_advisory'    => 'boolean',
        'occurred_at'    => 'datetime',
        'created_at'     => 'datetime',
        'confidence'     => 'float',
        'original_uid'   => 'integer',
        'escalated_uid'  => 'integer',
        'pid'            => 'integer',
    ];

    // Escalation types
    public const TYPE_UID_TRANSITION       = 'uid_transition';
    public const TYPE_SETUID_EXEC          = 'setuid_exec';
    public const TYPE_SUDO_INVOCATION      = 'sudo_invocation';
    public const TYPE_SU_INVOCATION        = 'su_invocation';
    public const TYPE_INTEGRITY_HIGH       = 'integrity_level_high';
    public const TYPE_TOKEN_IMPERSONATION  = 'token_impersonation';

    public const ESCALATION_TYPES = [
        self::TYPE_UID_TRANSITION,
        self::TYPE_SETUID_EXEC,
        self::TYPE_SUDO_INVOCATION,
        self::TYPE_SU_INVOCATION,
        self::TYPE_INTEGRITY_HIGH,
        self::TYPE_TOKEN_IMPERSONATION,
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public static function generateEscalationId(): string
    {
        return 'esc-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
