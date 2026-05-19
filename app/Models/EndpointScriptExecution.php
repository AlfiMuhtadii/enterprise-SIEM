<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a script/interpreter execution event.
 * Advisory-only — never triggers enforcement.
 */
class EndpointScriptExecution extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'execution_id', 'agent_id', 'process_name', 'parent_process_name',
        'command_line', 'script_source', 'is_encoded', 'decoded_preview',
        'script_hash', 'user', 'telemetry_source', 'host_id',
        'trace_id', 'is_advisory', 'occurred_at', 'created_at',
    ];

    protected $casts = [
        'is_encoded'  => 'boolean',
        'is_advisory' => 'boolean',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    // Script source types
    public const SOURCE_INLINE  = 'inline';
    public const SOURCE_FILE    = 'file';
    public const SOURCE_ENCODED = 'encoded';
    public const SOURCE_PIPED   = 'piped';

    // Telemetry sources
    public const TELEM_AGENT_PROC          = 'agent_proc';
    public const TELEM_SYSMON              = 'sysmon';
    public const TELEM_POWERSHELL_OPS      = 'powershell_operational';
    public const TELEM_ETW                 = 'etw';
    public const TELEM_SECURITY_EVENT      = 'security_event';

    // Known script interpreter names
    public const INTERPRETER_NAMES = [
        'powershell', 'powershell.exe', 'pwsh', 'pwsh.exe',
        'cmd', 'cmd.exe', 'wscript.exe', 'cscript.exe', 'mshta.exe',
        'python', 'python3', 'python2', 'python.exe',
        'perl', 'ruby', 'bash', 'sh', 'zsh', 'dash',
        'node', 'node.exe', 'php', 'php.exe',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public static function generateExecutionId(): string
    {
        return 'sex-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 36);
    }
}
