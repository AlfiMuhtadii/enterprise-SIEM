<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ENTERPRISE-054: ServiceNow Table API Adapter
 *
 * Creates ServiceNow incidents when XDR_SERVICENOW_URL + credentials are set
 * and XDR_SERVICENOW_DRY_RUN != false.
 *
 * Default: dry_run = true.
 * To enable: set XDR_SERVICENOW_URL, XDR_SERVICENOW_USER,
 *             XDR_SERVICENOW_PASSWORD + XDR_SERVICENOW_DRY_RUN=false.
 */
class ServiceNowRealAdapter
{
    public const DRY_RUN_DEFAULT = true;

    private string $instanceUrl;
    private string $user;
    private string $password;
    private bool   $dryRun;

    public function __construct()
    {
        $this->instanceUrl = rtrim((string) env('XDR_SERVICENOW_URL', ''), '/');
        $this->user        = (string) env('XDR_SERVICENOW_USER', '');
        $this->password    = (string) env('XDR_SERVICENOW_PASSWORD', '');
        $this->dryRun      = filter_var(env('XDR_SERVICENOW_DRY_RUN', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public function isConfigured(): bool
    {
        return $this->instanceUrl !== '' && $this->user !== '' && $this->password !== '';
    }

    /**
     * Create a ServiceNow incident from an XDR alert context.
     *
     * @param array $context {subject, body, severity, source_reference}
     * @return array {ok, simulated, dry_run, sys_id?}
     */
    public function createIncident(array $context): array
    {
        $payload = [
            'short_description' => substr((string) ($context['subject'] ?? 'XDR Alert'), 0, 160),
            'description'       => substr((string) ($context['body'] ?? ''), 0, 4000),
            'urgency'           => $this->mapUrgency($context['severity'] ?? 'medium'),
            'impact'            => $this->mapImpact($context['severity'] ?? 'medium'),
            'category'          => 'security',
            'assignment_group'  => 'SOC',
            'caller_id'         => 'xdr-soc',
        ];

        if (!$this->isConfigured()) {
            return ['ok' => true, 'simulated' => true, 'dry_run' => true, 'reason' => 'ServiceNow not configured'];
        }

        if ($this->dryRun) {
            Log::info('[ServiceNowRealAdapter][DRY-RUN] Would create SNOW incident', [
                'short_description' => $payload['short_description'],
            ]);
            return ['ok' => true, 'simulated' => true, 'dry_run' => true, 'sys_id' => null];
        }

        try {
            $url = "{$this->instanceUrl}/api/now/table/incident";
            $response = Http::withBasicAuth($this->user, $this->password)
                ->accept('application/json')
                ->timeout(10)
                ->post($url, $payload);

            return [
                'ok'          => $response->successful(),
                'simulated'   => false,
                'dry_run'     => false,
                'sys_id'      => $response->json('result.sys_id'),
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('[ServiceNowRealAdapter] createIncident failed: ' . $e->getMessage());
            return ['ok' => false, 'simulated' => false, 'dry_run' => false, 'error' => $e->getMessage()];
        }
    }

    private function mapUrgency(string $severity): int
    {
        return match ($severity) {
            'critical' => 1,
            'high'     => 2,
            default    => 3,
        };
    }

    private function mapImpact(string $severity): int
    {
        return match ($severity) {
            'critical' => 1,
            'high'     => 2,
            default    => 3,
        };
    }
}
