<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** @property string|null $tenant_id */
class ThreatHunt extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_FAILED = 'failed';

    public const SCOPE_LIVE = 'live';

    public const SCOPE_REPLAY = 'replay';

    protected $fillable = [
        'hunt_id', 'title', 'description', 'created_by',
        'executed_at', 'replay_scope', 'status', 'result_count', 'trace_id', 'tenant_id',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function queries(): HasMany
    {
        return $this->hasMany(ThreatHuntQuery::class, 'hunt_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ThreatHuntResult::class, 'hunt_id')->orderBy('id');
    }

    public static function generateHuntId(): string
    {
        $year = now()->year;
        $last = DB::table('threat_hunts')
            ->where('hunt_id', 'like', "HUNT-{$year}-%")
            ->orderByDesc('hunt_id')
            ->value('hunt_id');
        $seq = $last ? (int) substr($last, -5) + 1 : 1;

        return sprintf('HUNT-%d-%05d', $year, $seq);
    }
}
