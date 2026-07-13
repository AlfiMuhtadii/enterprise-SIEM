<?php

namespace App\Http\Controllers;

use App\Services\ClickHouseTelemetryReader;
use App\Services\TenantContextAuthority;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SocHuntController extends Controller
{
    public function __construct(private readonly TenantContextAuthority $tenantAuthority) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $results = $request->has('run') ? $this->queryTelemetry($request, $filters, 100) : collect();

        if ($request->has('run')) {
            $runId = 'hunt-run-'.Str::uuid();
            DB::table('soc_hunt_run_sessions')->insert([
                'run_id' => $runId,
                'executed_by' => $request->user()->email,
                'started_at' => now(),
                'completed_at' => now(),
                'filters' => json_encode($filters),
                'result_count' => $results->count(),
                'results' => json_encode($results->take(100)->values()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLogger::log($request->user()->email, 'hunt.run', 'threat_hunt_run', $runId, null, ['filters' => $filters, 'result_count' => $results->count()]);
        }

        return view('soc.hunts.index', [
            'filters' => $filters,
            'results' => $results,
            'savedHunts' => DB::table('soc_hunt_sessions')->orderByDesc('updated_at')->limit(50)->get(),
            'huntRuns' => DB::table('soc_hunt_run_sessions')->orderByDesc('started_at')->limit(50)->get(),
            'templates' => $this->templates(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'template_key' => ['nullable', 'string', 'max:80'],
        ]);
        $filters = $this->filters($request);
        $huntId = 'hunt-'.Str::uuid();
        DB::table('soc_hunt_sessions')->insert([
            'hunt_id' => $huntId,
            'name' => $data['name'],
            'template_key' => $data['template_key'] ?? null,
            'created_by' => $request->user()->email,
            'filters' => json_encode($filters),
            'query' => json_encode(['type' => 'telemetry_filter']),
            'saved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'hunt.save', 'threat_hunt', $huntId, null, ['filters' => $filters]);

        return back()->with('status', 'Hunt saved.');
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $this->queryTelemetry($request, $filters, 1000);
        AuditLogger::log($request->user()->email, 'hunt.export', 'threat_hunt', null, null, ['filters' => $filters, 'count' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            foreach ($rows as $row) {
                echo json_encode($row)."\n";
            }
        }, 'hunt-results-'.now()->format('Ymd-His').'.jsonl');
    }

    private function filters(Request $request): array
    {
        return [
            'minutes' => max(15, min(10080, (int) $request->query('minutes', $request->input('minutes', 1440)))),
            'host_id' => trim((string) ($request->query('host_id', $request->input('host_id', '')))),
            'user' => trim((string) ($request->query('user', $request->input('user', '')))),
            'process' => trim((string) ($request->query('process', $request->input('process', '')))),
            'ip' => trim((string) ($request->query('ip', $request->input('ip', '')))),
            'domain' => trim((string) ($request->query('domain', $request->input('domain', '')))),
            'event_type' => trim((string) ($request->query('event_type', $request->input('event_type', '')))),
            'severity' => trim((string) ($request->query('severity', $request->input('severity', '')))),
        ];
    }

    private function queryTelemetry(Request $request, array $filters, int $limit)
    {
        // ARCH-DB-SPLIT (read path): same ClickHouse-when-configured,
        // Postgres-fallback-on-failure pattern as the dashboard/endpoint
        // timeline read paths — an analyst's hunt search never just breaks
        // because ClickHouse happens to be unreachable.
        // TENANT-CLICKHOUSE-LEAK: scopes the ClickHouse query only -- the
        // Postgres fallback below has no tenant_id column on
        // telemetry_events at all (a separate, pre-existing, already-tracked
        // gap this doesn't touch).
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);
        $rows = null;
        if (config('xdr.infrastructure.clickhouse.telemetry_write_target') === 'clickhouse') {
            $rows = (new ClickHouseTelemetryReader())->huntSearch($filters, $limit, $tenantId);
        }
        if ($rows === null) {
            $q = DB::table('telemetry_events')
                ->where('ts', '>=', now()->subMinutes($filters['minutes']))
                ->orderByDesc('ts')
                ->limit($limit);
            foreach (['host_id', 'process' => 'process_name', 'event_type'] as $key => $column) {
                if (is_int($key)) {
                    $key = $column;
                }
                if ($filters[$key] !== '') {
                    $q->where($column, 'ilike', '%'.$filters[$key].'%');
                }
            }
            if ($filters['user'] !== '') {
                $q->where('user_name_hash', 'ilike', '%'.$filters['user'].'%');
            }
            if ($filters['ip'] !== '') {
                $q->where(fn ($inner) => $inner->where('src_ip', $filters['ip'])->orWhere('dst_ip', $filters['ip']));
            }
            if ($filters['domain'] !== '') {
                $q->whereRaw("payload::text ilike ?", ['%'.$filters['domain'].'%']);
            }
            $rows = $q->get();
        }
        $alertByHost = DB::table('security_alerts')->where('detected_at', '>=', now()->subMinutes($filters['minutes']))->get()->groupBy('actor_key');
        return $rows->map(function ($row) use ($alertByHost) {
            $row->correlated_alerts = $alertByHost->get($row->host_id, collect())->take(5)->values();
            return $row;
        });
    }

    private function templates(): array
    {
        return [
            'powershell_network' => 'PowerShell with network activity',
            'failed_login_source' => 'Repeated failed login source',
            'admin_fanout' => 'Admin protocol fan-out',
            'file_changes' => 'Recent endpoint file changes',
            'dns_beacon' => 'Repeated domain query hunt',
        ];
    }
}
