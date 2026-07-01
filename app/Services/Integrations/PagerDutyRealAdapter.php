<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ENTERPRISE-054: PagerDuty Events API v2 Adapter
 *
 * Sends trigger events to PagerDuty when XDR_PAGERDUTY_ROUTING_KEY is set
 * and XDR_PAGERDUTY_DRY_RUN != false.
 *
 * Default: dry_run = true.
 * To enable: set XDR_PAGERDUTY_ROUTING_KEY + XDR_PAGERDUTY_DRY_RUN=false.
 */
class PagerDutyRealAdapter
{
    public const DRY_RUN_DEFAULT  = true;
    private const EVENTS_API_URL  = 'https://events.pagerduty.com/v2/enqueue';
    private const SEVERITY_MAP    = ['critical' => 'critical', 'high' => 'error', 'medium' => 'warning', 'low' => 'info'];

    private string $routingKey;
    private bool   $dryRun;

    public function __construct()
    {
        $this->routingKey = (string) config('integrations.pagerduty.routing_key', '');
        $this->dryRun     = (bool) config('integrations.pagerduty.dry_run', true);
    }

    public function isConfigured(): bool
    {
        return $this->routingKey !== '';
    }

    /**
     * Trigger a PagerDuty incident.
     *
     * @param array $context {subject, body, severity, source_reference}
     * @return array {ok, simulated, dry_run, dedup_key?}
     */
    public function trigger(array $context): array
    {
        $severity  = self::SEVERITY_MAP[$context['severity'] ?? 'medium'] ?? 'warning';
        $dedupKey  = 'xdr-' . ($context['source_reference'] ?? uniqid());

        $payload = [
            'routing_key'  => $this->routingKey,
            'event_action' => 'trigger',
            'dedup_key'    => $dedupKey,
            'payload'      => [
                'summary'  => substr((string) ($context['subject'] ?? 'XDR Alert'), 0, 1024),
                'source'   => 'xdr-soc',
                'severity' => $severity,
                'custom_details' => [
                    'body'     => substr((string) ($context['body'] ?? ''), 0, 512),
                    'advisory' => true,
                ],
            ],
        ];

        if (!$this->isConfigured()) {
            return ['ok' => true, 'simulated' => true, 'dry_run' => true, 'reason' => 'routing_key not configured'];
        }

        if ($this->dryRun) {
            Log::info('[PagerDutyRealAdapter][DRY-RUN] Would trigger PD incident', ['summary' => $payload['payload']['summary']]);
            return ['ok' => true, 'simulated' => true, 'dry_run' => true, 'dedup_key' => $dedupKey];
        }

        try {
            $response = Http::timeout(5)->post(self::EVENTS_API_URL, $payload);
            return [
                'ok'          => $response->successful(),
                'simulated'   => false,
                'dry_run'     => false,
                'dedup_key'   => $dedupKey,
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('[PagerDutyRealAdapter] trigger failed: ' . $e->getMessage());
            return ['ok' => false, 'simulated' => false, 'dry_run' => false, 'error' => $e->getMessage()];
        }
    }
}
