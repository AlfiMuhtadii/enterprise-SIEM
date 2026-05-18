<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class EndpointExecutionChain extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'chain_id', 'agent_id', 'snapshot_id', 'chain_steps',
        'chain_length', 'involves_shell', 'involves_outbound', 'involves_persistence',
        'chain_score', 'trace_id', 'detected_at',
    ];

    protected $casts = [
        'chain_steps'         => 'array',
        'involves_shell'      => 'boolean',
        'involves_outbound'   => 'boolean',
        'involves_persistence'=> 'boolean',
        'chain_score'         => 'float',
        'detected_at'         => 'datetime',
        'created_at'          => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(EndpointAgent::class, 'agent_id');
    }

    public static function generateChainId(): string
    {
        $year = now()->year;
        $last = DB::table('endpoint_execution_chains')
            ->where('chain_id', 'like', "EC-{$year}-%")
            ->orderByDesc('chain_id')
            ->value('chain_id');
        $seq = $last ? (int) substr($last, -5) + 1 : 1;
        return sprintf('EC-%d-%05d', $year, $seq);
    }
}
