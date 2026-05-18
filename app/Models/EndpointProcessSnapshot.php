<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class EndpointProcessSnapshot extends Model
{
    protected $fillable = [
        'snapshot_id', 'agent_id', 'collected_at',
        'process_count', 'shell_count', 'long_lived_count', 'suspicious_count',
        'trace_id',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public function processEntries(): HasMany
    {
        return $this->hasMany(EndpointProcessEntry::class, 'snapshot_id')
            ->orderBy('process_name');
    }

    public function networkCorrelations(): HasMany
    {
        return $this->hasMany(EndpointNetworkCorrelation::class, 'snapshot_id');
    }

    public static function generateSnapshotId(): string
    {
        $year = now()->year;
        $last = DB::table('endpoint_process_snapshots')
            ->where('snapshot_id', 'like', "SNAP-{$year}-%")
            ->orderByDesc('snapshot_id')
            ->value('snapshot_id');
        $seq = $last ? (int) substr($last, -5) + 1 : 1;
        return sprintf('SNAP-%d-%05d', $year, $seq);
    }
}
