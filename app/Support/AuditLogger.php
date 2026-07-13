<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class AuditLogger
{
    public static function log(
        string $actor,
        string $action,
        string $targetType,
        ?string $targetId = null,
        mixed $before = null,
        mixed $after = null,
        mixed $meta = null,
        ?string $tenantId = null,
    ): void {
        DB::table('security_audit_trails')->insert([
            'occurred_at' => now(),
            'actor' => $actor ?: 'system',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'tenant_id' => $tenantId,
            'before_state' => $before === null ? null : json_encode($before),
            'after_state' => $after === null ? null : json_encode($after),
            'meta' => $meta === null ? null : json_encode($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
