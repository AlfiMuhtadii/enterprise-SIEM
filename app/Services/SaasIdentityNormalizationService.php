<?php

namespace App\Services;

use App\Models\ExternalIntegration;
use App\Models\IdentityProviderEvent;
use App\Models\SaasAuditEvent;
use Illuminate\Support\Str;

/**
 * SaaS & Identity Normalization Service — Phase 1.
 *
 * Normalizes inbound events from identity providers (Okta, Azure AD)
 * and SaaS audit logs (Office 365, GSuite, GitHub, Salesforce)
 * into structured append-only records.
 *
 * HARD CONSTRAINTS:
 * - No automatic account suspension or lockout — all findings are advisory
 * - No external credential harvesting — API tokens are config-referenced only
 * - All ingest is read-only from external source perspective (pull-only)
 * - Risk scoring is advisory; no autonomous enforcement action taken
 * - Records are append-only; no update/delete of ingested events
 */
class SaasIdentityNormalizationService
{
    public const MAX_INGEST_BATCH     = 500;
    public const RISK_SCORE_HIGH      = 0.75;
    public const RISK_SCORE_CRITICAL  = 0.90;

    // -----------------------------------------------------------------------
    // Identity provider event ingestion
    // -----------------------------------------------------------------------

    public function ingestIdentityEvent(ExternalIntegration $integration, array $raw): IdentityProviderEvent
    {
        $eventId = $raw['event_id'] ?? ('idp-' . Str::uuid());
        $existing = IdentityProviderEvent::where('event_id', $eventId)->first();
        if ($existing) {
            return $existing;  // idempotent
        }

        $isSuspicious = $this->scoreIdentityEvent($raw);

        return IdentityProviderEvent::create([
            'event_id'            => $eventId,
            'integration_id'      => $integration->id,
            'provider'            => $raw['provider'] ?? $integration->provider,
            'event_type'          => $raw['event_type'] ?? 'unknown',
            'user_id'             => $raw['user_id'] ?? null,
            'user_email'          => $raw['user_email'] ?? null,
            'source_ip'           => $raw['source_ip'] ?? null,
            'geo_country'         => $raw['geo_country'] ?? null,
            'user_agent'          => $raw['user_agent'] ?? null,
            'mfa_used'            => (bool) ($raw['mfa_used'] ?? false),
            'is_failed'           => (bool) ($raw['is_failed'] ?? false),
            'failed_attempt_count'=> (int) ($raw['failed_attempt_count'] ?? 0),
            'is_suspicious'       => $isSuspicious['is_suspicious'],
            'risk_score'          => $isSuspicious['risk_score'],
            'raw_attributes'      => $raw['raw_attributes'] ?? null,
            'trace_id'            => $raw['trace_id'] ?? ('idp-norm-' . Str::uuid()),
            'occurred_at'         => $raw['occurred_at'] ?? now(),
        ]);
    }

    public function ingestSaasAuditEvent(ExternalIntegration $integration, array $raw): SaasAuditEvent
    {
        $eventId = $raw['event_id'] ?? ('saas-' . Str::uuid());
        $existing = SaasAuditEvent::where('event_id', $eventId)->first();
        if ($existing) {
            return $existing;  // idempotent
        }

        $risk = $this->scoreSaasEvent($raw);

        return SaasAuditEvent::create([
            'event_id'          => $eventId,
            'integration_id'    => $integration->id,
            'provider'          => $raw['provider'] ?? $integration->provider,
            'actor_id'          => $raw['actor_id'] ?? null,
            'actor_email'       => $raw['actor_email'] ?? null,
            'actor_ip'          => $raw['actor_ip'] ?? null,
            'action'            => $raw['action'] ?? 'unknown',
            'resource_type'     => $raw['resource_type'] ?? null,
            'resource_id'       => $raw['resource_id'] ?? null,
            'target_id'         => $raw['target_id'] ?? null,
            'target_email'      => $raw['target_email'] ?? null,
            'additional_details'=> $raw['additional_details'] ?? null,
            'is_suspicious'     => $risk['is_suspicious'],
            'risk_score'        => $risk['risk_score'],
            'source_country'    => $raw['source_country'] ?? null,
            'user_agent'        => $raw['user_agent'] ?? null,
            'trace_id'          => $raw['trace_id'] ?? ('saas-norm-' . Str::uuid()),
            'occurred_at'       => $raw['occurred_at'] ?? now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Advisory risk scoring (no enforcement)
    // -----------------------------------------------------------------------

    private function scoreIdentityEvent(array $raw): array
    {
        $score = 0.0;
        $isFailed = (bool) ($raw['is_failed'] ?? false);
        $failedCount = (int) ($raw['failed_attempt_count'] ?? 0);
        $mfaUsed = (bool) ($raw['mfa_used'] ?? false);
        $eventType = $raw['event_type'] ?? '';

        if ($isFailed) {
            $score += 0.3;
        }
        if ($failedCount >= IdentityProviderEvent::BRUTE_FORCE_THRESHOLD) {
            $score += 0.5;
        }
        if (!$mfaUsed && in_array($eventType, ['login_success', 'privilege_escalation'], true)) {
            $score += 0.2;
        }
        if (in_array($eventType, ['account_locked', 'privilege_escalation', 'session_revoked'], true)) {
            $score += 0.25;
        }

        $score = min(1.0, $score);

        return [
            'is_suspicious' => $score >= self::RISK_SCORE_HIGH,
            'risk_score'    => round($score, 3),
            // advisory_only is implicit — no enforcement action is ever taken
        ];
    }

    private function scoreSaasEvent(array $raw): array
    {
        $score = 0.0;
        $action = $raw['action'] ?? '';

        if (in_array($action, SaasAuditEvent::SUSPICIOUS_ACTIONS, true)) {
            $score += 0.8;
        }
        if (!empty($raw['source_country']) && ($raw['source_country'] !== ($raw['expected_country'] ?? $raw['source_country']))) {
            $score += 0.2;
        }

        $score = min(1.0, $score);

        return [
            'is_suspicious' => $score >= self::RISK_SCORE_HIGH,
            'risk_score'    => round($score, 3),
        ];
    }

    // -----------------------------------------------------------------------
    // Batch ingestion
    // -----------------------------------------------------------------------

    public function ingestBatchIdentityEvents(ExternalIntegration $integration, array $events): array
    {
        $results = ['ingested' => 0, 'skipped' => 0, 'failed' => 0];
        $batch = array_slice($events, 0, self::MAX_INGEST_BATCH);

        foreach ($batch as $raw) {
            try {
                $eventId = $raw['event_id'] ?? null;
                if ($eventId && IdentityProviderEvent::where('event_id', $eventId)->exists()) {
                    $results['skipped']++;
                    continue;
                }
                $this->ingestIdentityEvent($integration, $raw);
                $results['ingested']++;
            } catch (\Exception) {
                $results['failed']++;
            }
        }

        return $results;
    }

    public function ingestBatchSaasEvents(ExternalIntegration $integration, array $events): array
    {
        $results = ['ingested' => 0, 'skipped' => 0, 'failed' => 0];
        $batch = array_slice($events, 0, self::MAX_INGEST_BATCH);

        foreach ($batch as $raw) {
            try {
                $eventId = $raw['event_id'] ?? null;
                if ($eventId && SaasAuditEvent::where('event_id', $eventId)->exists()) {
                    $results['skipped']++;
                    continue;
                }
                $this->ingestSaasAuditEvent($integration, $raw);
                $results['ingested']++;
            } catch (\Exception) {
                $results['failed']++;
            }
        }

        return $results;
    }

    public function getHighRiskIdentityEvents(int $hours = 24, int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return IdentityProviderEvent::where('is_suspicious', true)
            ->where('risk_score', '>=', self::RISK_SCORE_HIGH)
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->orderByDesc('risk_score')
            ->limit($limit)
            ->get();
    }

    public function getHighRiskSaasEvents(int $hours = 24, int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return SaasAuditEvent::where('is_suspicious', true)
            ->where('risk_score', '>=', self::RISK_SCORE_HIGH)
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->orderByDesc('risk_score')
            ->limit($limit)
            ->get();
    }
}
