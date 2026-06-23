<?php

namespace App\Services;

use App\Exceptions\TenantContextMissingException;
use App\Exceptions\TenantSpoofAttemptException;
use App\Models\TenantMembershipAuditEvent;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Tenant Context Authority — BACKLOG-TENANCY-020 / 021
 *
 * X-Tenant-ID is a SELECTOR, not an authority.
 *
 * Two operating modes controlled by XDR_TENANT_STRICT_MODE:
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ LEGACY MODE (XDR_TENANT_STRICT_MODE=false, default)                 │
 * │ • No header      → null (no scoping)                                │
 * │ • Admin          → header accepted, membership bypassed             │
 * │ • Zero members   → header accepted as-is (backward compat)          │
 * │ • Has members    → header must match a membership                   │
 * │                                                                     │
 * │ NOT PRODUCTION-SAFE. Use only during the migration period.          │
 * │ See docs/security/TENANT_STRICT_MODE.md.                            │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ STRICT MODE (XDR_TENANT_STRICT_MODE=true)                           │
 * │ • No header + requireTenantContext=true  → TenantContextMissing     │
 * │ • No header + requireTenantContext=false → null (listing routes OK) │
 * │ • Admin + no header + requireExplicitScope=true  → TenantContextMissing │
 * │ • Admin + X-Tenant-ID: _global           → null (explicit global)   │
 * │ • Admin + valid tenant header            → header returned (scoped) │
 * │ • Zero members   → TenantSpoofAttemptException (no passthrough)     │
 * │ • Has members    → header must match a membership                   │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Bypass priority order (both modes):
 *   1. Admin role (ADMIN_ROLE) — membership checks bypassed; still subject to
 *      requireExplicitScope on store routes (BACKLOG-023)
 *   2. System context (resolveSystemContext) — no User involved
 *
 * requireTenantContext=true  — object-scoped routes (show, review, index).
 * requireExplicitScope=true  — store/create routes; prevents accidental null
 *                              tenant_id; admin must use _global or a header.
 *
 * See docs/security/TENANT_STRICT_MODE.md
 *     docs/security/TENANT_NULL_MIGRATION_PLAN.md
 *     docs/security/TENANT_ISOLATION_POSTURE.md
 */
class TenantContextAuthority
{
    public const SOURCE_USER   = 'user';
    public const SOURCE_SYSTEM = 'system';

    /** Role that bypasses membership enforcement entirely (both modes). */
    public const ADMIN_ROLE = 'admin';

    /** Default mode when XDR_TENANT_STRICT_MODE is not set. */
    public const STRICT_MODE_DEFAULT = false;

    /**
     * Reserved X-Tenant-ID sentinel value that signals explicit global/unscoped
     * creation intent (admin-only). Prevents accidental null tenant_id on store
     * routes when requireExplicitScope=true is set (BACKLOG-023).
     *
     * Usage: send `X-Tenant-ID: _global` to create a record with tenant_id=NULL.
     * Non-admin users who send this header receive TenantSpoofAttemptException.
     */
    public const GLOBAL_SCOPE = '_global';

    /**
     * Validate the X-Tenant-ID header against the user's authorised memberships.
     *
     * @param  bool  $requireTenantContext  When true, strict mode rejects a missing header for
     *                                      all non-admin users. Use on index/show/review routes.
     *
     * @param  bool  $requireExplicitScope  When true, strict mode additionally blocks admin users
     *                                      from creating records with an implicit null tenant_id.
     *                                      Admin must supply either a tenant header or the
     *                                      GLOBAL_SCOPE sentinel ('_global') to signal intent.
     *                                      Use only on store/create routes (BACKLOG-023).
     *
     * @throws TenantContextMissingException  strict mode: header absent and required
     * @throws TenantSpoofAttemptException    user claims a tenant they are not a member of,
     *                                        or a non-admin uses the GLOBAL_SCOPE sentinel
     * @return string|null  validated tenant ID, or null for unscoped/global access
     */
    public function validateAndResolve(
        Request $request,
        User    $user,
        bool    $requireTenantContext = false,
        bool    $requireExplicitScope = false,
    ): ?string {
        $requestedTenant = $request->header('X-Tenant-ID');
        $strictMode      = $this->isStrictMode();

        // ── GLOBAL_SCOPE sentinel ────────────────────────────────────────────
        // Must be evaluated before admin bypass so non-admins are rejected.
        if ($requestedTenant === self::GLOBAL_SCOPE) {
            if (!$this->isAdminBypass($user)) {
                throw new TenantSpoofAttemptException($user->id, $requestedTenant, []);
            }
            // Admin explicitly requested global/unscoped creation → null tenant_id
            return null;
        }

        // ── Admin bypass ─────────────────────────────────────────────────────
        // Admins skip membership checks. In strict mode, store routes additionally
        // require an explicit scope declaration (requireExplicitScope=true).
        if ($this->isAdminBypass($user)) {
            if ($strictMode && $requireExplicitScope && $requestedTenant === null) {
                // Admin must declare intent: supply X-Tenant-ID or use '_global'
                throw new TenantContextMissingException($user->id);
            }
            return $requestedTenant; // null → unscoped read; non-null → tenant-scoped
        }

        // ── No header ────────────────────────────────────────────────────────
        if ($requestedTenant === null) {
            if ($strictMode && $requireTenantContext) {
                throw new TenantContextMissingException($user->id);
            }
            return null;
        }

        // ── Header present — validate against memberships ────────────────────
        $allowedTenants = $this->getUserTenants($user);

        if (empty($allowedTenants)) {
            if ($strictMode) {
                throw new TenantSpoofAttemptException($user->id, $requestedTenant, []);
            }
            return $requestedTenant; // legacy pass-through
        }

        if (!in_array($requestedTenant, $allowedTenants, true)) {
            throw new TenantSpoofAttemptException($user->id, $requestedTenant, $allowedTenants);
        }

        return $requestedTenant;
    }

    /**
     * Returns true when strict mode is active.
     * Reads XDR_TENANT_STRICT_MODE from config/xdr.php.
     */
    public function isStrictMode(): bool
    {
        return (bool) config('xdr.tenancy.strict_mode', self::STRICT_MODE_DEFAULT);
    }

    /**
     * Return a context record for internal / system callers (Artisan, Go services).
     * Strict mode does not apply — no User is involved.
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
            return $existing;
        }

        if ($existing && !$existing->is_active) {
            $existing->update([
                'is_active'  => true,
                'granted_by' => $grantedBy,
                'granted_at' => now(),
                'revoked_by' => null,
                'revoked_at' => null,
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
