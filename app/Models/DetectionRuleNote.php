<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetectionRuleNote extends Model
{
    protected $fillable = ['rule_id', 'user_id', 'note_type', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
