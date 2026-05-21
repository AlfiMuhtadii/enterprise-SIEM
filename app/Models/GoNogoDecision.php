<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoNogoDecision extends Model
{
    protected $fillable = [
        'decision_id', 'request_id', 'release_id', 'decision', 'rationale', 'decided_by',
    ];

    public const DECISION_GO    = 'go';
    public const DECISION_NO_GO = 'no_go';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('GoNogoDecision is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
