<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsePlanNote extends Model
{
    protected $fillable = [
        'response_plan_id', 'author_user_id', 'note_type', 'body',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ResponsePlan::class, 'response_plan_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
