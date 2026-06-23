<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown in strict mode when an authenticated user accesses a tenant-scoped
 * route without supplying the required X-Tenant-ID header.
 *
 * Distinct from TenantSpoofAttemptException (claiming a tenant the user is
 * not a member of) and TenantBoundaryViolationException (accessing a record
 * belonging to a different tenant than the validated request context).
 *
 * This exception fires at the authority layer, before any DB access.
 * Maps to HTTP 403 Forbidden.
 *
 * Only occurs when XDR_TENANT_STRICT_MODE=true AND the route was called
 * with requireTenantContext=true.
 */
class TenantContextMissingException extends RuntimeException
{
    public function __construct(
        int    $userId,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "Tenant context required: user {$userId} must supply X-Tenant-ID header on this route (strict mode active)"
        );
    }
}
