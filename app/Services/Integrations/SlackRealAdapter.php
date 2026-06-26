<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ENTERPRISE-054: Slack Real Webhook Adapter
 *
 * Sends Slack Incoming Webhook messages when XDR_SLACK_WEBHOOK_URL is set
 * and XDR_SLACK_DRY_RUN != false.
 *
 * Default: dry_run = true (simulated delivery, no real HTTP call).
 * To enable: set XDR_SLACK_WEBHOOK_URL + XDR_SLACK_DRY_RUN=false.
 */
class SlackRealAdapter
{
    public const DRY_RUN_DEFAULT = true;

    private string $webhookUrl;
    private bool   $dryRun;

    public function __construct()
    {
        $this->webhookUrl = (string) env('XDR_SLACK_WEBHOOK_URL', '');
        $this->dryRun     = filter_var(env('XDR_SLACK_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public function isConfigured(): bool
    {
        return $this->webhookUrl !== '' && str_starts_with($this->webhookUrl, 'https://');
    }

    /**
     * Dispatch a notification to Slack.
     *
     * @param array $payload Structured payload (text, blocks)
     * @return array {ok, simulated, dry_run, status_code?}
     */
    public function dispatch(array $payload): array
    {
        $sanitized = $this->sanitizePayload($payload);

        if (!$this->isConfigured()) {
            return [
                'ok'        => true,
                'simulated' => true,
                'dry_run'   => true,
                'reason'    => 'XDR_SLACK_WEBHOOK_URL not configured',
            ];
        }

        if ($this->dryRun) {
            Log::info('[SlackRealAdapter][DRY-RUN] Would POST to Slack webhook', ['text' => $sanitized['text'] ?? '']);
            return [
                'ok'        => true,
                'simulated' => true,
                'dry_run'   => true,
                'reason'    => 'dry_run=true',
            ];
        }

        try {
            $response = Http::timeout(5)->post($this->webhookUrl, $sanitized);
            return [
                'ok'          => $response->successful(),
                'simulated'   => false,
                'dry_run'     => false,
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('[SlackRealAdapter] dispatch failed: ' . $e->getMessage());
            return ['ok' => false, 'simulated' => false, 'dry_run' => false, 'error' => $e->getMessage()];
        }
    }

    private function sanitizePayload(array $payload): array
    {
        return [
            'text'     => substr((string) ($payload['text'] ?? 'XDR Alert'), 0, 150),
            'blocks'   => array_slice($payload['blocks'] ?? [], 0, 10),
            'username' => 'XDR SOC',
        ];
    }
}
