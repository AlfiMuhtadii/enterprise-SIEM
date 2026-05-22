<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BackupMetadata extends Model
{
    protected $table = 'backup_metadata';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_VERIFIED  = 'verified';
    public const STATUS_CORRUPTED = 'corrupted';
    public const STATUS_UNKNOWN   = 'unknown';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_CORRUPTED,
        self::STATUS_UNKNOWN,
    ];

    public const TYPE_FULL        = 'full';
    public const TYPE_INCREMENTAL = 'incremental';
    public const TYPE_SNAPSHOT    = 'snapshot';
    public const TYPE_SCHEMA      = 'schema';

    protected $fillable = [
        'backup_id', 'backup_type', 'scope', 'backup_at', 'size_bytes',
        'checksum', 'storage_path', 'status', 'validation_note', 'trace_id',
    ];

    protected $casts = ['backup_at' => 'datetime'];

    public static function generateBackupId(): string
    {
        return 'bkp-' . Str::uuid();
    }

    public function validations(): HasMany
    {
        return $this->hasMany(RecoveryValidation::class, 'backup_id', 'backup_id');
    }
}
