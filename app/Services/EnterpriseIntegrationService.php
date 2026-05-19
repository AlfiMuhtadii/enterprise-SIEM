<?php

namespace App\Services;

use App\Models\ExternalCaseLink;
use App\Models\ExternalIntegration;
use App\Models\IntegrationSyncEvent;
use Illuminate\Support\Str;

/**
 * Enterprise Integration Service — Phase 1.
 *
 * Manages integration configuration, audit-safe sync records, and
 * case linking between SOC investigations and external ticketing systems.
 *
 * HARD CONSTRAINTS:
 * - All sync is dry_run=true by default; never autonomously closes external tickets
 * - No credential storage or retrieval via this service
 * - No bidirectional destructive sync — external writes are advisory/push-only
 * - All operations are append-only audited; no hidden sync behavior
 * - auto_closed=false is enforced on all case links
 */
class EnterpriseIntegrationService
{
    public const MAX_SYNC_RECORDS_PER_RUN = 500;
    public const MAX_CASE_LINKS_PER_INVESTIGATION = 10;

    // -----------------------------------------------------------------------
    // Integration management
    // -----------------------------------------------------------------------

    public function registerIntegration(array $data): ExternalIntegration
    {
        return ExternalIntegration::create([
            'integration_id'    => 'int-' . Str::uuid(),
            'integration_type'  => $data['integration_type'],
            'provider'          => $data['provider'],
            'name'              => substr($data['name'] ?? '', 0, 255),
            'status'            => ExternalIntegration::STATUS_INACTIVE,
            'config'            => $data['config'] ?? null,
            'field_mappings'    => $data['field_mappings'] ?? null,
            'inbound_enabled'   => (bool) ($data['inbound_enabled'] ?? false),
            'outbound_enabled'  => (bool) ($data['outbound_enabled'] ?? false),
            'sync_advisory_only'=> true,  // always starts advisory
        ]);
    }

    public function activateIntegration(ExternalIntegration $integration): ExternalIntegration
    {
        $integration->update([
            'status'           => ExternalIntegration::STATUS_ACTIVE,
            'last_sync_status' => null,
            'last_error'       => null,
        ]);
        return $integration->fresh();
    }

    public function disableIntegration(ExternalIntegration $integration): ExternalIntegration
    {
        $integration->update([
            'status'          => ExternalIntegration::STATUS_DISABLED,
            'last_sync_status'=> 'disabled',
        ]);
        return $integration->fresh();
    }

    // -----------------------------------------------------------------------
    // Sync event recording (append-only)
    // -----------------------------------------------------------------------

    /**
     * Record the start of a sync run. Always dry_run=true unless explicitly enabled.
     */
    public function recordSyncStart(
        ExternalIntegration $integration,
        string $direction,
        string $syncType,
        bool $dryRun = true,
        ?int $actorId = null
    ): IntegrationSyncEvent {
        return IntegrationSyncEvent::create([
            'sync_id'        => 'sync-' . Str::uuid(),
            'integration_id' => $integration->id,
            'direction'      => $direction,
            'sync_type'      => $syncType,
            'status'         => 'started',
            'dry_run'        => $dryRun,
            'advisory_only'  => true,
            'started_at'     => now(),
            'created_by'     => $actorId,
            'trace_id'       => 'eis-' . Str::uuid(),
        ]);
    }

    public function completeSyncEvent(
        IntegrationSyncEvent $syncEvent,
        int $processed,
        int $failed,
        array $summary = []
    ): IntegrationSyncEvent {
        // Append-only: create a new completed record rather than updating
        return IntegrationSyncEvent::create([
            'sync_id'           => 'sync-' . Str::uuid(),
            'integration_id'    => $syncEvent->integration_id,
            'direction'         => $syncEvent->direction,
            'sync_type'         => $syncEvent->sync_type,
            'status'            => 'completed',
            'records_processed' => min($processed, self::MAX_SYNC_RECORDS_PER_RUN),
            'records_failed'    => $failed,
            'dry_run'           => $syncEvent->dry_run,
            'advisory_only'     => true,
            'summary'           => $summary,
            'started_at'        => $syncEvent->started_at,
            'completed_at'      => now(),
            'trace_id'          => $syncEvent->trace_id,
            'created_by'        => $syncEvent->created_by,
        ]);
    }

    public function recordSyncFailure(
        IntegrationSyncEvent $syncEvent,
        string $errorDetail
    ): IntegrationSyncEvent {
        return IntegrationSyncEvent::create([
            'sync_id'       => 'sync-' . Str::uuid(),
            'integration_id'=> $syncEvent->integration_id,
            'direction'     => $syncEvent->direction,
            'sync_type'     => $syncEvent->sync_type,
            'status'        => 'failed',
            'dry_run'       => $syncEvent->dry_run,
            'advisory_only' => true,
            'error_detail'  => substr($errorDetail, 0, 2000),
            'started_at'    => $syncEvent->started_at,
            'completed_at'  => now(),
            'trace_id'      => $syncEvent->trace_id,
            'created_by'    => $syncEvent->created_by,
        ]);
    }

    // -----------------------------------------------------------------------
    // Case linking (append-only, no autonomous closure)
    // -----------------------------------------------------------------------

    public function linkCase(
        string $investigationId,
        ExternalIntegration $integration,
        array $data,
        ?int $actorId = null
    ): ExternalCaseLink {
        // Hard constraint: auto_closed is never set to true
        return ExternalCaseLink::create([
            'link_id'            => 'link-' . Str::uuid(),
            'investigation_id'   => $investigationId,
            'integration_id'     => $integration->id,
            'external_ticket_id' => $data['external_ticket_id'],
            'external_ticket_url'=> $data['external_ticket_url'] ?? null,
            'external_status'    => $data['external_status'] ?? null,
            'link_direction'     => $data['link_direction'] ?? 'soc_to_external',
            'sync_advisory_only' => true,
            'auto_closed'        => false,  // NEVER autonomous ticket closure
            'external_metadata'  => $data['external_metadata'] ?? null,
            'trace_id'           => 'ecl-' . Str::uuid(),
            'created_by'         => $actorId,
        ]);
    }

    public function getActiveIntegrations(string $type): \Illuminate\Database\Eloquent\Collection
    {
        return ExternalIntegration::where('status', ExternalIntegration::STATUS_ACTIVE)
            ->where('integration_type', $type)
            ->get();
    }

    public function getSyncHistory(ExternalIntegration $integration, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return IntegrationSyncEvent::where('integration_id', $integration->id)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();
    }

    public function getCaseLinksByInvestigation(string $investigationId): \Illuminate\Database\Eloquent\Collection
    {
        return ExternalCaseLink::where('investigation_id', $investigationId)
            ->orderByDesc('created_at')
            ->get();
    }
}
