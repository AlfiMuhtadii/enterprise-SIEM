<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a request attempts to access a resource belonging to a different tenant.
 *
 * This is an object-level authorization failure, not an authentication failure.
 * It maps to HTTP 403 Forbidden (the resource exists but is not accessible
 * to the requesting tenant context).
 */
class TenantBoundaryViolationException extends RuntimeException
{
    public function __construct(
        string $recordTenantId,
        string $requestTenantId,
        string $message = ''
    ) {
        parent::__construct(
            $message ?: "Cross-tenant access denied: record belongs to '{$recordTenantId}', request context is '{$requestTenantId}'"
        );
    }
}
