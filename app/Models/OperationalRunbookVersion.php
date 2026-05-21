<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalRunbookVersion extends Model
{
    protected $fillable = [
        'version_id', 'runbook_id', 'version', 'content', 'content_hash', 'authored_by',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('OperationalRunbookVersion is append-only and cannot be updated.');
        }
        if (empty($this->created_at)) {
            $this->created_at = now();
            $this->updated_at = now();
        }
        return parent::save($options);
    }
}
