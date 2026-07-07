<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetectionBacktestMatch extends Model
{
    protected $table = 'detection_backtest_matches';

    public $timestamps = false;

    protected $fillable = [
        'match_id',
        'run_id',
        'rule_id',
        'actor_key',
        'event_count',
        'sample_events',
        'created_at',
    ];

    protected $casts = [
        'sample_events' => 'array',
        'created_at' => 'datetime',
    ];
}
