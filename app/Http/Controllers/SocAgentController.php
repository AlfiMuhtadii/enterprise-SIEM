<?php

namespace App\Http\Controllers;

use App\Models\EndpointAgent;
use App\Services\EndpointResponseCommandService;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SocAgentController extends Controller
{
    // Maps legacy SOC command types to ALLOWED_TYPES in EndpointResponseCommandService.
    // Unmapped types (rotate-agent-secret, restart-agent-loop) have no safe equivalent
    // and must be rejected — do NOT add them to ALLOWED_TYPES.
    private const LEGACY_TYPE_MAP = [
        'collect-now'       => 'collect_diagnostics',
        'flush-local-queue' => 'upload_health_snapshot',
        'refresh-policy'    => 'refresh_config',
    ];

    public function __construct(
        private EndpointResponseCommandService $commandService,
        private TenantBoundaryService $tenantBoundary,
        private TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request): View
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $offlineAfter = (int) config('soc.agent_offline_after_seconds', 180);
        $agents = DB::table('endpoint_agents')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('last_seen_at')
            ->paginate(25)
            ->withQueryString();
        $agents->getCollection()->transform(function ($agent) use ($offlineAfter) {
            $agent->computed_status = \App\Services\EndpointAgentService::computeStatus($agent->last_seen_at, $offlineAfter);
            return $agent;
        });

        return view('soc.agents.index', [
            'agents' => $agents,
            'policies' => DB::table('agent_policies')->orderByDesc('is_default')->orderBy('name')->get(),
            'commands' => DB::table('agent_commands')->orderByDesc('queued_at')->limit(100)->get(),
            'responses' => DB::table('soc_response_workflows')->orderByDesc('created_at')->limit(100)->get(),
            // TENANT-FORENSIC-ISOLATION: forensic_collection_jobs now carries
            // tenant_id; scoped the same way endpoint_agents/tamperAlerts
            // below already are.
            'forensicJobs' => DB::table('forensic_collection_jobs')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'streamMetrics' => DB::table('agent_stream_metrics')->orderByDesc('reported_at')->limit(100)->get(),
            'releases' => DB::table('agent_releases')->orderByDesc('released_at')->get(),
            'tamperAlerts' => DB::table('security_alerts')
                ->whereIn('alert_type', ['AGENT_STALE_OR_STOPPED', 'AGENT_RETRY_QUEUE_GROWTH', 'AGENT_REPEATED_DELIVERY_FAILURE', 'AGENT_POLICY_OUTDATED', 'AGENT_STARTUP_INTEGRITY_FAILED', 'AGENT_UNEXPECTED_RESTART'])
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('detected_at')
                ->limit(50)
                ->get(),
            'latestVersion' => config('soc.agent_latest_version', '0.2.0'),
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'policy_id' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'collection_interval_seconds' => ['required', 'integer', 'min:10', 'max:86400'],
            'max_batch_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'collect_process' => ['nullable', 'boolean'],
            'collect_network' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $before = DB::table('agent_policies')->where('policy_id', $data['policy_id'])->first();
        if (!empty($data['is_default'])) {
            DB::table('agent_policies')->update(['is_default' => false]);
        }
        DB::table('agent_policies')->updateOrInsert(
            ['policy_id' => $data['policy_id']],
            [
                'name' => $data['name'],
                'version' => DB::raw($before ? 'version + 1' : '1'),
                'is_default' => !empty($data['is_default']),
                'collection_interval_seconds' => $data['collection_interval_seconds'],
                'enabled_collectors' => json_encode([
                    'process' => $request->boolean('collect_process'),
                    'network' => $request->boolean('collect_network'),
                ]),
                'max_batch_size' => $data['max_batch_size'],
                'retry_policy' => json_encode(['initial_backoff_seconds' => 1, 'max_backoff_seconds' => 300]),
                'telemetry_categories' => json_encode(['endpoint', 'network']),
                'metadata' => json_encode(['updated_from' => 'soc-ui']),
                'created_at' => $before->created_at ?? now(),
                'updated_at' => now(),
            ]
        );
        $after = DB::table('agent_policies')->where('policy_id', $data['policy_id'])->first();
        AuditLogger::log($request->user()->email, 'agent.policy.upsert', 'agent_policy', $data['policy_id'], $before, $after);

        return back()->with('status', 'Agent policy saved.');
    }

    public function assignPolicy(Request $request, string $agentId): RedirectResponse
    {
        $data = $request->validate(['policy_id' => ['required', 'string', 'max:80']]);
        $before = DB::table('endpoint_agents')->where('agent_id', $agentId)->first();
        abort_if(!$before, 404);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $this->tenantBoundary->assertAccess($before->tenant_id, $tenantId);

        DB::table('endpoint_agents')->where('agent_id', $agentId)->update([
            'policy_id' => $data['policy_id'],
            'updated_at' => now(),
        ]);
        $after = DB::table('endpoint_agents')->where('agent_id', $agentId)->first();
        AuditLogger::log($request->user()->email, 'agent.policy.assign', 'agent', $agentId, $before, $after, tenantId: $tenantId);

        return back()->with('status', 'Policy assigned.');
    }

    public function queueCommand(Request $request, string $agentId): RedirectResponse
    {
        $data = $request->validate([
            'command_type' => ['required', 'in:collect-now,flush-local-queue,rotate-agent-secret,refresh-policy,restart-agent-loop'],
        ]);

        $mapped = self::LEGACY_TYPE_MAP[$data['command_type']] ?? null;
        if ($mapped === null) {
            return back()->withErrors([
                'command_type' => "Command type '{$data['command_type']}' is not supported in the current response framework. Use the Endpoint Response panel for safe commands.",
            ]);
        }

        $agent = EndpointAgent::where('agent_id', $agentId)->first();
        abort_if(!$agent, 404);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());
        $this->tenantBoundary->assertAccess($agent->tenant_id, $tenantId);

        $command = $this->commandService->createCommand(
            $agent,
            $mapped,
            [],
            $request->user()->id,
        );

        AuditLogger::log($request->user()->email, 'agent.command.queue', 'agent', $agentId, null, [
            'command_id'  => $command->command_id,
            'legacy_type' => $data['command_type'],
            'mapped_type' => $mapped,
        ], tenantId: $tenantId);

        return back()->with('status', 'Command queued via response framework.');
    }
}
