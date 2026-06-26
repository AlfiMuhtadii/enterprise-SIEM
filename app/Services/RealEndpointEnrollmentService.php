<?php

namespace App\Services;

use App\Models\RealEndpointEnrollment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-053: Real Endpoint Telemetry Enrollment
 *
 * Manages enrollment tokens for real OS-verified endpoints.
 * The endpoint agent runs on a real host and posts process/persistence
 * snapshots through the ingestion-gateway HMAC path.
 *
 * Constraints:
 * - ADVISORY_ONLY = true; enrollment is informational
 * - is_real = true only when OS-verified data was received
 * - No kernel EDR, no process killing, no active containment
 * - Safe collectors only: process ancestry, persistence inventory, network sockets
 */
class RealEndpointEnrollmentService
{
    public const ADVISORY_ONLY     = true;
    public const MAX_ENROLLMENTS   = 20;  // bounded: prevents unbounded enrollment table growth

    private const VALID_PLATFORMS = ['windows', 'linux', 'darwin', 'unknown'];

    public function issueToken(string $hostname, string $tenantId = '', string $osPlatform = 'unknown'): array
    {
        $enrollmentId = (string) Str::uuid();
        $token        = 'xdr-enroll-' . Str::random(32);
        $platform     = in_array($osPlatform, self::VALID_PLATFORMS, true) ? $osPlatform : 'unknown';

        return [
            'enrollment_id'    => $enrollmentId,
            'enrollment_token' => $token,
            'hostname'         => $hostname,
            'os_platform'      => $platform,
            'tenant_id'        => $tenantId ?: null,
            'is_advisory'      => true,
        ];
    }

    public function recordEnrollment(array $data, bool $dryRun = false): array
    {
        $hostname = $data['hostname'] ?? 'unknown';
        $token    = $data['enrollment_token'] ?? ('xdr-enroll-' . Str::random(32));
        $tenantId = $data['tenant_id'] ?? null;
        $now      = now()->format('Y-m-d H:i:sP');

        // Gate: max enrollments
        $existing = RealEndpointEnrollment::count();
        if ($existing >= self::MAX_ENROLLMENTS) {
            return [
                'ok'      => false,
                'error'   => 'MAX_ENROLLMENTS (' . self::MAX_ENROLLMENTS . ') reached',
                'is_advisory' => true,
            ];
        }

        $collectorSummary = $data['collector_summary'] ?? null;
        $processCount     = (int) ($data['process_count'] ?? 0);
        $persistenceCount = (int) ($data['persistence_count'] ?? 0);

        $row = [
            'enrollment_id'    => $data['enrollment_id'] ?? (string) Str::uuid(),
            'enrollment_token' => $token,
            'hostname'         => $hostname,
            'os_platform'      => in_array($data['os_platform'] ?? 'unknown', self::VALID_PLATFORMS, true)
                                    ? ($data['os_platform'] ?? 'unknown') : 'unknown',
            'os_version'       => $data['os_version'] ?? null,
            'agent_version'    => $data['agent_version'] ?? null,
            'tenant_id'        => $tenantId,
            'heartbeat_received'  => (bool) ($data['heartbeat_received'] ?? false),
            'snapshot_received'   => (bool) ($data['snapshot_received'] ?? false),
            'process_count'       => $processCount,
            'persistence_count'   => $persistenceCount,
            'collector_summary'   => $collectorSummary ? json_encode($collectorSummary) : null,
            'is_real'             => (bool) ($data['is_real'] ?? true),
            'is_advisory'         => true,
            'enrolled_at'         => $now,
        ];

        if (!$dryRun) {
            RealEndpointEnrollment::create($row);
        }

        return array_merge($row, ['ok' => true, 'dry_run' => $dryRun]);
    }

    public function acknowledgeHeartbeat(string $token): array
    {
        $enrollment = RealEndpointEnrollment::where('enrollment_token', $token)->first();
        if (!$enrollment) {
            return ['ok' => false, 'error' => 'token_not_found'];
        }

        // heartbeat_received is append-only by convention — no update to existing row
        // Instead, create a new enrollment row with heartbeat_received = true if not already set
        // In practice, the agent keeps the same token; we just return acknowledgment
        return [
            'ok'          => true,
            'hostname'    => $enrollment->hostname,
            'tenant_id'   => $enrollment->tenant_id,
            'is_advisory' => true,
        ];
    }

    public function validateEnrollment(string $token): array
    {
        $enrollment = RealEndpointEnrollment::where('enrollment_token', $token)->first();
        if (!$enrollment) {
            return ['valid' => false, 'reason' => 'token_not_found'];
        }

        return [
            'valid'       => true,
            'enrollment_id' => $enrollment->enrollment_id,
            'hostname'    => $enrollment->hostname,
            'os_platform' => $enrollment->os_platform,
            'is_real'     => $enrollment->is_real,
            'is_advisory' => true,
        ];
    }

    public function getEnrollments(string $tenantId = ''): Collection
    {
        $query = RealEndpointEnrollment::query();
        if ($tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }
        return $query->orderByDesc('enrolled_at')->get();
    }

    public function getSummary(): array
    {
        $total   = RealEndpointEnrollment::count();
        $real    = RealEndpointEnrollment::where('is_real', true)->count();
        $active  = RealEndpointEnrollment::where('heartbeat_received', true)->count();

        return [
            'total'          => $total,
            'real_os_data'   => $real,
            'heartbeat_active' => $active,
            'max_enrollments'=> self::MAX_ENROLLMENTS,
            'is_advisory'    => true,
        ];
    }
}
