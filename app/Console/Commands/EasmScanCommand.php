<?php

namespace App\Console\Commands;

use App\Models\EasmAssetRiskScore;
use App\Models\EasmFinding;
use App\Models\WebsiteAsset;
use App\Services\EasmPassiveScanService;
use App\Services\EasmPostureHistoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * EasmScanCommand — run a passive EASM scan against a registered asset or ad-hoc URL.
 *
 * Signature: easm:scan {asset_id?} {--tenant=} {--url=} {--dry-run} {--output=} {--profile=local}
 *
 * The --dry-run flag validates the target without actually scanning.
 * The $scannerFn property is injectable for tests (avoids shelling out to Python).
 */
class EasmScanCommand extends Command
{
    protected $signature = 'easm:scan
        {asset_id? : Asset ID to scan}
        {--tenant= : Tenant ID}
        {--url= : URL (creates temporary scan without persisting asset)}
        {--dry-run : Validate without scanning}
        {--output= : JSON output path}
        {--profile=local : Profile (local/staging/production)}';

    protected $description = 'Run a passive EASM scan against a registered asset or ad-hoc URL (advisory-only)';

    /**
     * Injectable scanner function for tests.
     * Signature: callable(string $url, string|int $assetId, string $tenantId, string $tmpFile): array
     */
    public ?\Closure $scannerFn = null;

    public function __construct(
        private readonly EasmPassiveScanService      $service,
        private readonly EasmPostureHistoryService   $historyService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $assetId  = $this->argument('asset_id');
        $urlOpt   = $this->option('url');
        $tenantId = $this->option('tenant') ?? 'default';
        $dryRun   = $this->option('dry-run');
        $output   = $this->option('output');
        $profile  = $this->option('profile') ?? 'local';

        if (empty($assetId) && empty($urlOpt)) {
            $this->error('You must provide either asset_id or --url.');
            return self::FAILURE;
        }

        // ---- Resolve asset -----------------------------------------------
        if (!empty($assetId)) {
            try {
                $asset = $this->service->validateOwnership((int) $assetId, $tenantId);
            } catch (\RuntimeException $e) {
                $this->error('Asset error: ' . $e->getMessage());
                return self::FAILURE;
            }
            $url = $asset->url;
        } else {
            // Ad-hoc URL — validate but do not persist
            $validation = $this->service->validateUrl($urlOpt);
            if (!$validation['valid']) {
                $this->error('Invalid URL: ' . $validation['error']);
                return self::FAILURE;
            }
            $url = $validation['normalized_url'];
            // Temporary in-memory asset (not persisted)
            $asset = new WebsiteAsset([
                'id'        => 0,
                'tenant_id' => $tenantId,
                'name'      => $validation['hostname'],
                'url'       => $url,
                'hostname'  => $validation['hostname'],
                'scheme'    => $validation['scheme'],
            ]);
        }

        // ---- URL safety re-check -----------------------------------------
        $validation = $this->service->validateUrl($url);
        if (!$validation['valid']) {
            $this->error('URL failed safety check: ' . $validation['error']);
            return self::FAILURE;
        }

        // ---- Dry-run ---------------------------------------------------------
        if ($dryRun) {
            $this->info('[DRY-RUN] Would scan: ' . $url);
            $this->info('[DRY-RUN] Tenant:     ' . $tenantId);
            $this->info('[DRY-RUN] Policy:     passive');
            $this->info('[DRY-RUN] Checks:     ' . implode(', ', $this->service->getScanPolicyChecks('passive')));
            $this->info('[DRY-RUN] No scan run or findings will be created.');
            return self::SUCCESS;
        }

        // ---- Invoke scanner -------------------------------------------------
        $tmpFile = sys_get_temp_dir() . '/easm_scan_' . Str::uuid() . '.json';

        try {
            if ($this->scannerFn !== null) {
                $scanResult = ($this->scannerFn)($url, $asset->id ?? 0, $tenantId, $tmpFile);
            } else {
                $scriptPath = base_path('scripts/xdr_easm_passive_scan.py');
                $safeUrl    = escapeshellarg($url);
                $safeAsset  = escapeshellarg((string) ($asset->id ?? 0));
                $safeTenant = escapeshellarg($tenantId);
                $safeTmp    = escapeshellarg($tmpFile);
                $safeProf   = escapeshellarg($profile);

                $cmd = "python {$scriptPath} --url {$safeUrl} --asset-id {$safeAsset} --tenant-id {$safeTenant} --output {$safeTmp} --profile {$safeProf}";
                exec($cmd, $cmdOutput, $exitCode);

                if ($exitCode !== 0) {
                    $this->error('Scanner exited with code ' . $exitCode);
                    return self::FAILURE;
                }

                if (!file_exists($tmpFile)) {
                    $this->error('Scanner did not produce output file: ' . $tmpFile);
                    return self::FAILURE;
                }

                $json = file_get_contents($tmpFile);
                $scanResult = json_decode($json, true);
                @unlink($tmpFile);
            }
        } catch (\Throwable $e) {
            $this->error('Scanner error: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!is_array($scanResult)) {
            $this->error('Scanner returned invalid data.');
            return self::FAILURE;
        }

        // ---- Normalise findings + capture previous state for posture diff ----
        $rawChecks  = $scanResult['checks'] ?? [];
        $normalised = $this->service->normalizeCheckResults($rawChecks, $asset);

        // Fetch previous open findings BEFORE upserting (required for accurate diff)
        $previousFindingsForDiff = [];
        if ($asset->id) {
            $previousFindingsForDiff = EasmFinding::where('asset_id', $asset->id)
                ->where('tenant_id', $tenantId)
                ->where('status', 'open')
                ->get()
                ->map(fn ($f) => ['finding_key' => $f->finding_key, 'severity' => $f->severity])
                ->toArray();
        }

        // ---- Upsert findings ------------------------------------------------
        $upserted = [];
        foreach ($normalised as $nf) {
            if ($asset->id) {
                $upserted[] = $this->service->upsertFinding($nf);
            }
        }

        // ---- Create scan run (append-only) ----------------------------------
        $run = null;
        if ($asset->id) {
            $run = $this->service->createScanRun([
                'tenant_id'       => $tenantId,
                'asset_id'        => $asset->id,
                'scan_policy'     => 'passive',
                'target_hostname' => $asset->hostname,
                'target_url'      => $url,
                'status'          => isset($scanResult['error']) ? 'failed' : 'completed',
                'started_at'      => $scanResult['started_at'] ?? now()->format('Y-m-d H:i:sP'),
                'finished_at'     => $scanResult['finished_at'] ?? now()->format('Y-m-d H:i:sP'),
                'finding_count'   => count($upserted),
                'checks_run'      => array_column($rawChecks, 'check'),
                'error_summary'   => $scanResult['error'] ?? null,
                'is_dry_run'      => false,
            ]);
        }

        // ---- Posture history update ------------------------------------------
        if ($run !== null && $asset->id) {
            try {
                $score = $this->historyService->computePostureScore($normalised);
                $diffs = $this->historyService->diffFindings($previousFindingsForDiff, $normalised);
                $this->historyService->createPostureSnapshot($run, $asset, $normalised, $score, $diffs);
                $this->historyService->recordFindingChanges($run, $asset, $diffs);
                $prevScoreRecord = EasmAssetRiskScore::where('asset_id', $asset->id)
                    ->where('tenant_id', $tenantId)
                    ->first();
                $this->historyService->upsertAssetRiskScore($asset, $score, $prevScoreRecord?->risk_score);
            } catch (\Throwable $e) {
                $this->warn('Posture history update failed: ' . $e->getMessage());
            }
        }

        // ---- Report ----------------------------------------------------------
        if (!empty($output) && $run !== null) {
            $report = $this->service->generateReport($run, $asset, $normalised);
            File::put($output, json_encode($report, JSON_PRETTY_PRINT));
            $this->info('Report written to: ' . $output);
        }

        $this->info('Scan complete. Findings: ' . count($upserted));

        return self::SUCCESS;
    }
}
