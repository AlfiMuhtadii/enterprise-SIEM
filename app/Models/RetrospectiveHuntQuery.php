<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetrospectiveHuntQuery extends Model
{
    protected $fillable = [
        'query_id', 'investigation_id', 'session_id', 'name', 'description',
        'domain', 'filters', 'time_range_start', 'time_range_end',
        'result_count', 'status', 'is_advisory', 'replay_safe',
        'created_by', 'last_executed_at',
    ];

    protected $casts = [
        'filters'          => 'array',
        'result_count'     => 'integer',
        'is_advisory'      => 'boolean',
        'replay_safe'      => 'boolean',
        'last_executed_at' => 'datetime',
    ];

    public const STATUSES = ['draft', 'executed', 'archived'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
