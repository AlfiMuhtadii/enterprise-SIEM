<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class EndpointResponseCommand extends Model
{
    // -----------------------------------------------------------------------
    // Allowed command types — strictly allowlisted, no destructive actions
    // -----------------------------------------------------------------------

    public const ALLOWED_TYPES = [
        'noop',
        'collect_diagnostics',
        'refresh_config',
        'upload_health_snapshot',
    ];

    /**
     * Explicitly named forbidden types for documentation and test clarity.
     * The agent ALSO enforces this list independently (defense in depth).
     * These types must NEVER be added to ALLOWED_TYPES.
     */
    public const FORBIDDEN_TYPES = [
        'isolate_host',
        'kill_process',
        'quarantine_file',
        'delete_file',
        'remove_persistence',
        'block_ip',
        'disable_service',
        'wipe_disk',
    ];

    // -----------------------------------------------------------------------
    // State machine
    // -----------------------------------------------------------------------

    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_DISPATCHED       = 'dispatched';
    public const STATUS_ACKNOWLEDGED     = 'acknowledged';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_FAILED           = 'failed';
    public const STATUS_CANCELLED        = 'cancelled';

    public const STATE_TRANSITIONS = [
        'draft'            => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'cancelled'],
        'approved'         => ['dispatched', 'cancelled'],
        'dispatched'       => ['acknowledged', 'failed'],
        'acknowledged'     => ['completed', 'failed'],
        'completed'        => [],
        'failed'           => [],
        'cancelled'        => [],
    ];

    protected $fillable = [
        'command_id', 'agent_id', 'command_type', 'status',
        'parameters', 'result', 'error_message',
        'created_by', 'approved_by', 'approved_at',
        'dispatched_at', 'acknowledged_at', 'completed_at',
        'trace_id',
    ];

    protected $casts = [
        'parameters'      => 'array',
        'result'          => 'array',
        'approved_at'     => 'datetime',
        'dispatched_at'   => 'datetime',
        'acknowledged_at' => 'datetime',
        'completed_at'    => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EndpointResponseCommandEvent::class, 'command_id')
            ->orderBy('occurred_at');
    }

    public static function generateCommandId(): string
    {
        $year = now()->year;
        $last = DB::table('endpoint_response_commands')
            ->where('command_id', 'like', "CMD-{$year}-%")
            ->orderByDesc('command_id')
            ->value('command_id');
        $seq = $last ? (int) substr($last, -5) + 1 : 1;
        return sprintf('CMD-%d-%05d', $year, $seq);
    }

    public static function isAllowedType(string $type): bool
    {
        return in_array($type, self::ALLOWED_TYPES, true);
    }

    public static function isForbiddenType(string $type): bool
    {
        return in_array($type, self::FORBIDDEN_TYPES, true);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::STATE_TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }
}
