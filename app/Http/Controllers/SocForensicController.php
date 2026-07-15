<?php

namespace App\Http\Controllers;

use App\Services\ClickHouseTelemetryReader;
use App\Services\TenantBoundaryService;
use App\Services\TenantContextAuthority;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

class SocForensicController extends Controller
{
    public function __construct(
        private readonly TenantContextAuthority $tenantAuthority,
        private readonly TenantBoundaryService $tenantBoundary,
    ) {}

    public function request(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'agent_id' => ['nullable', 'string', 'max:96'],
            'host_id' => ['nullable', 'string', 'max:128'],
            'collection_type' => ['required', 'in:process-snapshot,network-snapshot,telemetry-snapshot,recent-alert-evidence,endpoint-diagnostics'],
        ]);
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);

        // TENANT-FORENSIC-ISOLATION: reject collecting against an agent that
        // belongs to a different tenant, the same assertAccess() pattern
        // SocAgentController already uses for agent-scoped actions.
        if (!empty($data['agent_id'])) {
            $agent = DB::table('endpoint_agents')->where('agent_id', $data['agent_id'])->first();
            if ($agent) {
                $this->tenantBoundary->assertAccess($agent->tenant_id, $tenantId);
            }
        }

        $jobId = 'forensic-'.Str::uuid();
        DB::table('forensic_collection_jobs')->insert([
            'job_id' => $jobId,
            'tenant_id' => $tenantId,
            'agent_id' => $data['agent_id'] ?? null,
            'host_id' => $data['host_id'] ?? null,
            'collection_type' => $data['collection_type'],
            'status' => 'pending_approval',
            'requested_by' => $request->user()->email,
            'metadata' => json_encode(['source' => 'soc-ui']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'forensic.request', 'forensic_job', $jobId, null, $data);

        return back()->with('status', 'Forensic collection requested.');
    }

    public function decide(Request $request, string $jobId): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $job = DB::table('forensic_collection_jobs')->where('job_id', $jobId)->first();
        abort_if(!$job, 404);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);
        $this->tenantBoundary->assertAccess($job->tenant_id, $tenantId);

        if ($data['decision'] === 'reject') {
            DB::table('forensic_collection_jobs')->where('job_id', $jobId)->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->email,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLogger::log($request->user()->email, 'forensic.reject', 'forensic_job', $jobId, $job, null);
            return back()->with('status', 'Forensic collection rejected.');
        }

        $artifact = $this->buildArtifact($job, $tenantId);
        DB::table('forensic_collection_jobs')->where('job_id', $jobId)->update([
            'status' => 'completed',
            'approved_by' => $request->user()->email,
            'approved_at' => now(),
            'completed_at' => now(),
            'artifact_path' => $artifact['path'],
            'artifact_sha256' => $artifact['sha256'],
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'forensic.approve_collect', 'forensic_job', $jobId, $job, $artifact);

        return back()->with('status', 'Forensic bundle completed.');
    }

    private function buildArtifact(object $job, ?string $tenantId = null): array
    {
        $dir = storage_path('app/forensics');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $base = $dir.DIRECTORY_SEPARATOR.$job->job_id;
        // ARCH-DB-SPLIT (read path): same ClickHouse-when-configured,
        // Postgres-fallback-on-failure pattern as the other migrated read
        // paths — a forensic artifact must never fail to build just
        // because ClickHouse happens to be unreachable.
        // TENANT-POSTGRES-FALLBACK-TELEMETRY: the Postgres fallback below is
        // now scoped the same way the ClickHouse path already was
        // (TENANT-CLICKHOUSE-LEAK), using the approving analyst's own
        // resolved tenant context.
        $telemetry = null;
        if (config('xdr.infrastructure.clickhouse.telemetry_write_target') === 'clickhouse') {
            $telemetry = (new ClickHouseTelemetryReader())->forensicHostEvents($job->host_id, 200, $tenantId);
        }
        if ($telemetry === null) {
            $telemetry = DB::table('telemetry_events')
                ->when($job->host_id, fn ($q) => $q->where('host_id', $job->host_id))
                ->when($tenantId !== null, fn ($q) => $q->where(fn ($inner) => $inner->where('tenant_id', $tenantId)->orWhereNull('tenant_id')))
                ->orderByDesc('ts')
                ->limit(200)
                ->get();
        }
        // TENANT-FORENSIC-ISOLATION: both tables are MUTABLE_TABLES
        // (Phase-3-backfilled), so scoped strictly like SocAgentController's
        // own endpoint_agents/security_alerts queries -- no OR-null legacy
        // fallback.
        $payload = [
            'job' => $job,
            'telemetry' => $telemetry,
            'alerts' => DB::table('security_alerts')
                ->when($job->host_id, fn ($q) => $q->whereRaw('evidence::text ilike ?', ['%'.$job->host_id.'%']))
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->orderByDesc('detected_at')
                ->limit(100)
                ->get(),
            'agent' => $job->agent_id
                ? DB::table('endpoint_agents')
                    ->where('agent_id', $job->agent_id)
                    ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->first()
                : null,
        ];
        $jsonPath = $base.'.json';
        file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $finalPath = $jsonPath;
        if (class_exists(ZipArchive::class)) {
            $zipPath = $base.'.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $zip->addFile($jsonPath, basename($jsonPath));
                $zip->close();
                $finalPath = $zipPath;
            }
        }
        return ['path' => $finalPath, 'sha256' => hash_file('sha256', $finalPath)];
    }
}
