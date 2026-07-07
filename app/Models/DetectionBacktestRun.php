<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetectionBacktestRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'rule_ids',
        'window_start',
        'window_end',
        'telemetry_event_count',
        'triggered_by',
        'created_at',
    ];

    protected $casts = [
        'rule_ids' => 'array',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function matches()
    {
        return $this->hasMany(DetectionBacktestMatch::class, 'run_id', 'run_id');
    }
}
