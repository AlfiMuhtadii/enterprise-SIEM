<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class EndpointBeaconPattern extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'pattern_id', 'agent_id', 'snapshot_id', 'process_name',
        'remote_ip', 'remote_port', 'connection_count',
        'avg_interval_seconds', 'interval_variance', 'destination_reuse_score',
        'trace_id', 'detected_at',
    ];

    protected $casts = [
        'avg_interval_seconds'   => 'float',
        'interval_variance'      => 'float',
        'destination_reuse_score'=> 'float',
        'detected_at'            => 'datetime',
        'created_at'             => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public static function generatePatternId(): string
    {
        $year = now()->year;
        $last = DB::table('endpoint_beacon_patterns')
            ->where('pattern_id', 'like', "BP-{$year}-%")
            ->orderByDesc('pattern_id')
            ->value('pattern_id');
        $seq = $last ? (int) substr($last, -5) + 1 : 1;
        return sprintf('BP-%d-%05d', $year, $seq);
    }
}
