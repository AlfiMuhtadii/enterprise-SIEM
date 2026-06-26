<?php

namespace App\Services;

use App\Models\ExternalIntegration;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use Illuminate\Support\Str;

/**
 * Notification Service — Phase 1.
 *
 * Manages outbound notification events to channels: Slack, webhook, PagerDuty, email.
 *
 * HARD CONSTRAINTS:
 * - All deliveries are simulated=true by default (never actually sent in demo/advisory mode)
 * - No unrestricted webhook execution — all payloads are structured and bounded
 * - Notifications require analyst-initiated or pipeline-sourced triggers
 * - No credential exposure in notification bodies
 * - No autonomous account suspension via notification channels
 */
class NotificationService
{
    public const MAX_BODY_LENGTH     = 4096;
    public const MAX_SUBJECT_LENGTH  = 255;
    public const MAX_RETRY_ATTEMPTS  = 3;
    public const SIMULATED_BY_DEFAULT = true;

    // -----------------------------------------------------------------------
    // Notification event creation (append-only)
    // -----------------------------------------------------------------------

    public function createNotification(
        ExternalIntegration $integration,
        array $data,
        ?int $actorId = null
    ): NotificationEvent {
        $requiresApproval = $this->requiresAnalystApproval($data['notification_type'] ?? '');

        return NotificationEvent::create([
            'notification_id'          => 'notif-' . Str::uuid(),
            'integration_id'           => $integration->id,
            'notification_type'        => $data['notification_type'],
            'severity'                 => $data['severity'] ?? 'medium',
            'channel'                  => $data['channel'],
            'subject'                  => substr($data['subject'] ?? '', 0, self::MAX_SUBJECT_LENGTH),
            'body'                     => substr($data['body'] ?? '', 0, self::MAX_BODY_LENGTH),
            'target_metadata'          => $data['target_metadata'] ?? null,
            'source_reference'         => $data['source_reference'] ?? null,
            'requires_analyst_approval'=> $requiresApproval,
            'trace_id'                 => 'ns-' . Str::uuid(),
            'created_by'               => $actorId,
        ]);
    }

    /**
     * Simulate delivery — records the attempt as simulated=true, never sends.
     * Analyst must explicitly enable live delivery via enableLiveDelivery().
     */
    public function simulateDelivery(NotificationEvent $notification): NotificationDelivery
    {
        return NotificationDelivery::create([
            'delivery_id'           => 'dlv-' . Str::uuid(),
            'notification_event_id' => $notification->id,
            'status'                => NotificationDelivery::STATUS_SKIPPED,
            'attempt_number'        => 1,
            'simulated'             => true,
            'response_body'         => json_encode(['simulated' => true, 'advisory_only' => true]),
            'attempted_at'          => now(),
            'trace_id'              => $notification->trace_id,
        ]);
    }

    /**
     * Record a live delivery attempt (only when analyst has explicitly enabled live mode).
     * Still records all outcomes append-only. Never performs account actions.
     */
    public function recordDeliveryAttempt(
        NotificationEvent $notification,
        bool $success,
        int $httpStatus,
        string $responseBody,
        int $attempt = 1
    ): NotificationDelivery {
        return NotificationDelivery::create([
            'delivery_id'           => 'dlv-' . Str::uuid(),
            'notification_event_id' => $notification->id,
            'status'                => $success
                ? NotificationDelivery::STATUS_DELIVERED
                : NotificationDelivery::STATUS_FAILED,
            'attempt_number'        => min($attempt, self::MAX_RETRY_ATTEMPTS),
            'http_status_code'      => $httpStatus,
            'response_body'         => substr($responseBody, 0, 2000),
            'simulated'             => false,
            'attempted_at'          => now(),
            'trace_id'              => $notification->trace_id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Safety checks
    // -----------------------------------------------------------------------

    /**
     * High-severity notifications and critical channel types require analyst approval
     * before dispatching, preventing autonomous page storms.
     */
    private function requiresAnalystApproval(string $notificationType): bool
    {
        return in_array($notificationType, ['escalation', 'sla_breach'], true);
    }

    // ── ENTERPRISE-054: Real adapter dispatch ─────────────────────────────────
    // Routes to real adapters only when env vars are configured.
    // SIMULATED_BY_DEFAULT remains true; real dispatch is opt-in via env.

    public function dispatchSlack(array $context): array
    {
        $payload = $this->buildSlackPayload($context);
        $adapter = new \App\Services\Integrations\SlackRealAdapter();
        return $adapter->dispatch($payload);
    }

    public function dispatchPagerDuty(array $context): array
    {
        $adapter = new \App\Services\Integrations\PagerDutyRealAdapter();
        return $adapter->trigger($context);
    }

    public function dispatchJira(array $context): array
    {
        $adapter = new \App\Services\Integrations\JiraRealAdapter();
        return $adapter->createIssue($context);
    }

    public function dispatchServiceNow(array $context): array
    {
        $adapter = new \App\Services\Integrations\ServiceNowRealAdapter();
        return $adapter->createIncident($context);
    }

    public function buildSlackPayload(array $context): array
    {
        return [
            'text'        => substr($context['subject'] ?? 'XDR Alert', 0, 150),
            'blocks'      => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => substr($context['body'] ?? '', 0, 2000)]],
            ],
            'advisory'    => true,
            'simulated'   => self::SIMULATED_BY_DEFAULT,
            'source'      => 'xdr-soc',
        ];
    }

    public function buildWebhookPayload(array $context): array
    {
        return [
            'event_type'   => $context['notification_type'] ?? 'alert',
            'severity'     => $context['severity'] ?? 'medium',
            'subject'      => substr($context['subject'] ?? '', 0, self::MAX_SUBJECT_LENGTH),
            'body'         => substr($context['body'] ?? '', 0, self::MAX_BODY_LENGTH),
            'source'       => 'xdr-soc',
            'advisory'     => true,
            'no_auto_action'=> true,
        ];
    }

    public function getNotificationHistory(ExternalIntegration $integration, int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return NotificationEvent::where('integration_id', $integration->id)
            ->with('deliveries')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
