<?php

namespace App\Services;

use App\Exceptions\TenantSpoofAttemptException;
use App\Models\TenantMembershipAuditEvent;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Tenant Context Authority — BACKLOG-TENANCY-020
 *
 * X-Tenant-ID is a SELECTOR, not an authority.
 *
 * The header names which tenant the user wants to operate in. This service
 * validates that the authenticated user is actually a member of that tenant
 * before the header value is used for any query scoping or record access.
 *
 * Posture rules (in priority order):
 * 1. No X-Tenant-ID header → null (no scoping; backward compatible).
 * 2. Admin role → bypass membership check; header is accepted as-is.
 * 3. User with zero memberships → header is accepted as-is (backward compat,
 *    single-tenant / legacy mode). No enforcement until memberships are granted.
 * 4. User with ≥1 active memberships → header MUST match one of them, or
 *    TenantSpoofAttemptException is thrown (→ HTTP 403).
 *
 * System / Artisan context:
 * resolveSystemContext() returns a context record with SOURCE_SYSTEM, bypassing
 * all user-membership checks. Use this in Artisan commands and Go/Python services
 * that do not have an authenticated Laravel User.
 *
 * See docs/security/TENANT_NULL_MIGRATION_PLAN.md and
 *     docs/security/TENANT_ISOLATION_POSTURE.md
 */
class TenantContextAuthority
{
    public const SOURCE_USER   = 'user';
    public const SOURCE_SYSTEM = 'system';

    // Role that bypasses membership enforcement entirely.
    public const ADMIN_ROLE = 'admin';

    /**
     * Validate the X-Tenant-ID header against the user's authorised memberships.
     *
     * @throws TenantSpoofAttemptException when the user claims a tenant they are not a member of
     * @return string|null validated tenant ID, or null if no header was supplied
     */
    public function validateAndResolve(Request $request, User $user): ?string
    {
        $requestedTenant = $request->header('X-Tenant-ID');

        // Rule 1: no header → no scoping
        if ($requestedTenant === null) {
            return null;
        }

        // Rule 2: admin bypass
        if ($this->isAdminBypass($user)) {
            return $requestedTenant;
        }

        $allowedTenants = $this->getUserTenants($user);

        // Rule 3: no memberships → backward-compatible pass-through
        if (empty($allowedTenants)) {
            return $requestedTenant;
        }

        // Rule 4: membership check
        if (!in_array($requestedTenant, $allowedTenants, true)) {
            throw new TenantSpoofAttemptException($user->id, $requestedTenant, $allowedTenants);
        }

        return $requestedTenant;
    }

    /**
     * Return a context record for internal / system callers (Artisan, Go services).
     * No user-membership check is performed.
     */
    public function resolveSystemContext(?string $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'source'    => self::SOURCE_SYSTEM,
        ];
    }

    /**
     * Return a context record for a validated user request.
     */
    public function resolveUserContext(?string $tenantId, int $userId): array
    {
        return [
            'tenant_id' => $tenantId,
            'source'    => self::SOURCE_USER,
            'user_id'   => $userId,
        ];
    }

    /**
     * Returns true when the user's role grants full tenant bypass.
     */
    public function isAdminBypass(User $user): bool
    {
        return $user->role === self::ADMIN_ROLE;
    }

    /**
     * Returns the list of tenant IDs the user is an active member of.
     */
    public function getUserTenants(User $user): array
    {
        return UserTenantMembership::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('tenant_id')
            ->toArray();
    }

    /**
     * Grant membership to a tenant. Idempotent for already-active memberships.
     * Re-activates a previously revoked membership.
     */
    public function grantMembership(int $userId, string $tenantId, int $grantedBy): UserTenantMembership
    {
        $existing = UserTenantMembership::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($existing && $existing->is_active) {
            return $existing; // idempotent
        }

        if ($existing && !$existing->is_active) {
            $existing->update([
                'is_active'   => true,
                'granted_by'  => $grantedBy,
                'granted_at'  => now(),
                'revoked_by'  => null,
                'revoked_at'  => null,
            ]);
            $this->appendAuditEvent($existing->membership_id, $userId, $tenantId, 're_granted', $grantedBy);
            return $existing->fresh();
        }

        $membership = UserTenantMembership::create([
            'membership_id' => Str::uuid()->toString(),
            'user_id'       => $userId,
            'tenant_id'     => $tenantId,
            'granted_by'    => $grantedBy,
            'granted_at'    => now(),
            'is_active'     => true,
        ]);

        $this->appendAuditEvent($membership->membership_id, $userId, $tenantId, 'granted', $grantedBy);

        return $membership;
    }

    /**
     * Revoke a user's membership from a tenant.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function revokeMembership(int $userId, string $tenantId, int $revokedBy): void
    {
        $membership = UserTenantMembership::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->firstOrFail();

        $membership->update([
            'is_active'  => false,
            'revoked_by' => $revokedBy,
            'revoked_at' => now(),
        ]);

        $this->appendAuditEvent($membership->membership_id, $userId, $tenantId, 'revoked', $revokedBy);
    }

    private function appendAuditEvent(
        string $membershipId,
        int    $userId,
        string $tenantId,
        string $eventType,
        int    $actorId,
        array  $metadata = [],
    ): void {
        TenantMembershipAuditEvent::create([
            'event_id'      => 'tmae-' . Str::uuid(),
            'membership_id' => $membershipId,
            'user_id'       => $userId,
            'tenant_id'     => $tenantId,
            'event_type'    => $eventType,
            'actor_id'      => $actorId,
            'metadata'      => $metadata ?: null,
            'created_at'    => now(),
        ]);
    }
}
