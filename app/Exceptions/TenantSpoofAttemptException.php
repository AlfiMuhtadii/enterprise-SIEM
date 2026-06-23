<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an authenticated user supplies an X-Tenant-ID header value that
 * does not match any of their authorised tenant memberships.
 *
 * This is a spoofing attempt — the user is presenting themselves as belonging
 * to a tenant they are not a member of.
 *
 * Distinct from TenantBoundaryViolationException (which fires when a record
 * belongs to a different tenant than the validated request context).
 *
 * Maps to HTTP 403 Forbidden.
 */
class TenantSpoofAttemptException extends RuntimeException
{
    public function __construct(
        int    $userId,
        string $claimedTenantId,
        array  $allowedTenants = [],
        string $message = '',
    ) {
        $allowed = empty($allowedTenants) ? 'none' : implode(', ', $allowedTenants);
        parent::__construct(
            $message ?: "Tenant spoof attempt: user {$userId} claimed tenant '{$claimedTenantId}' but is only a member of: [{$allowed}]"
        );
    }
}
