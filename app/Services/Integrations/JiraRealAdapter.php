<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ENTERPRISE-054: Jira Cloud REST API v3 Adapter
 *
 * Creates Jira issues when XDR_JIRA_URL + XDR_JIRA_API_TOKEN are set
 * and XDR_JIRA_DRY_RUN != false.
 *
 * Default: dry_run = true.
 * To enable: set XDR_JIRA_URL, XDR_JIRA_EMAIL, XDR_JIRA_API_TOKEN,
 *             XDR_JIRA_PROJECT_KEY + XDR_JIRA_DRY_RUN=false.
 */
class JiraRealAdapter
{
    public const DRY_RUN_DEFAULT = true;
    private const ISSUE_TYPE     = 'Task';

    private string $jiraUrl;
    private string $email;
    private string $apiToken;
    private string $projectKey;
    private bool   $dryRun;

    public function __construct()
    {
        $this->jiraUrl    = rtrim((string) config('integrations.jira.url', ''), '/');
        $this->email      = (string) config('integrations.jira.email', '');
        $this->apiToken   = (string) config('integrations.jira.api_token', '');
        $this->projectKey = (string) config('integrations.jira.project_key', 'SOC');
        $this->dryRun     = (bool) config('integrations.jira.dry_run', true);
    }

    public function isConfigured(): bool
    {
        return $this->jiraUrl !== '' && $this->apiToken !== '' && $this->email !== '';
    }

    /**
     * Create a Jira issue from an XDR investigation context.
     *
     * @param array $context {subject, body, severity, source_reference}
     * @return array {ok, simulated, dry_run, issue_key?}
     */
    public function createIssue(array $context): array
    {
        $payload = [
            'fields' => [
                'project'     => ['key' => $this->projectKey],
                'summary'     => substr((string) ($context['subject'] ?? 'XDR Alert'), 0, 255),
                'description' => [
                    'type'    => 'doc',
                    'version' => 1,
                    'content' => [[
                        'type'    => 'paragraph',
                        'content' => [['type' => 'text', 'text' => substr((string) ($context['body'] ?? ''), 0, 1024)]],
                    ]],
                ],
                'issuetype'   => ['name' => self::ISSUE_TYPE],
                'priority'    => ['name' => $this->mapPriority($context['severity'] ?? 'medium')],
                'labels'      => ['xdr-soc', 'advisory'],
            ],
        ];

        if (!$this->isConfigured()) {
            return ['ok' => true, 'simulated' => true, 'dry_run' => true, 'reason' => 'Jira not configured'];
        }

        if ($this->dryRun) {
            Log::info('[JiraRealAdapter][DRY-RUN] Would create Jira issue', ['summary' => $payload['fields']['summary']]);
            return ['ok' => true, 'simulated' => true, 'dry_run' => true, 'issue_key' => null];
        }

        try {
            $response = Http::withBasicAuth($this->email, $this->apiToken)
                ->timeout(10)
                ->post("{$this->jiraUrl}/rest/api/3/issue", $payload);

            return [
                'ok'          => $response->successful(),
                'simulated'   => false,
                'dry_run'     => false,
                'issue_key'   => $response->json('key'),
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('[JiraRealAdapter] createIssue failed: ' . $e->getMessage());
            return ['ok' => false, 'simulated' => false, 'dry_run' => false, 'error' => $e->getMessage()];
        }
    }

    private function mapPriority(string $severity): string
    {
        return match ($severity) {
            'critical' => 'Highest',
            'high'     => 'High',
            'medium'   => 'Medium',
            default    => 'Low',
        };
    }
}
