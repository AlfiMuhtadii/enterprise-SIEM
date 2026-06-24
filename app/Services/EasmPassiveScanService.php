<?php

namespace App\Services;

use App\Models\WebsiteAsset;
use App\Models\EasmScanRun;
use App\Models\EasmFinding;
use Illuminate\Support\Str;

/**
 * EasmPassiveScanService — External Attack Surface Management passive posture monitoring.
 *
 * ADVISORY_ONLY = true. No exploit scanning, no active containment, no autonomous remediation.
 * Findings are advisory hints only; they never create SecurityIncident records
 * and never modify detection rules or ACTIVE_ALLOWLIST.
 */
class EasmPassiveScanService
{
    public const ADVISORY_ONLY = true;

    public const SCAN_TIMEOUT_SECONDS    = 30;
    public const REQUEST_TIMEOUT_SECONDS = 5;
    public const MAX_REDIRECTS           = 5;
    public const MAX_ROBOTS_BYTES        = 65_536;
    public const MAX_SITEMAP_BYTES       = 65_536;

    public const SCAN_POLICIES         = ['passive', 'safe', 'active_approved'];
    public const IMPLEMENTED_POLICIES  = ['passive'];

    public const FINDING_CATEGORIES = [
        'dns', 'tls', 'security_header', 'cookie',
        'redirect', 'exposure', 'technology', 'availability',
    ];

    public const SEVERITIES = ['info', 'low', 'medium', 'high'];

    // =========================================================================
    // URL validation
    // =========================================================================

    /**
     * Validate and normalise a URL.
     *
     * Returns:
     *   ['valid' => true,  'hostname' => ..., 'scheme' => ..., 'normalized_url' => ...]
     *   ['valid' => false, 'error'    => ...]
     */
    public function validateUrl(string $url): array
    {
        if (trim($url) === '') {
            return ['valid' => false, 'error' => 'URL must not be empty.'];
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return ['valid' => false, 'error' => 'URL could not be parsed or has no hostname.'];
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'error' => "Unsupported scheme '{$scheme}'. Only http and https are allowed."];
        }

        $hostname = strtolower($parts['host']);

        if ($this->isPrivateOrInternalHost($hostname)) {
            return ['valid' => false, 'error' => "Host '{$hostname}' resolves to a private or internal address and cannot be scanned."];
        }

        // Reconstruct normalised URL
        $normalised = $scheme . '://' . $hostname;
        if (!empty($parts['port'])) {
            $normalised .= ':' . $parts['port'];
        }
        if (!empty($parts['path'])) {
            $normalised .= $parts['path'];
        }
        if (!empty($parts['query'])) {
            $normalised .= '?' . $parts['query'];
        }

        return [
            'valid'          => true,
            'hostname'       => $hostname,
            'scheme'         => $scheme,
            'normalized_url' => $normalised,
        ];
    }

    /**
     * Return true if the hostname is a private / internal host that must never be scanned.
     */
    public function isPrivateOrInternalHost(string $hostname): bool
    {
        $lower = strtolower(trim($hostname));

        // Explicit localhost variants
        if (in_array($lower, ['localhost', 'localhost.localdomain'], true)) {
            return true;
        }

        // Internal TLD suffixes
        $internalTlds = ['.local', '.internal', '.lan', '.corp', '.test', '.example', '.invalid'];
        foreach ($internalTlds as $tld) {
            if (str_ends_with($lower, $tld)) {
                return true;
            }
        }

        // IP address check — covers 127.x, 10.x, 192.168.x, 172.16-31.x, link-local, etc.
        if (filter_var($lower, FILTER_VALIDATE_IP)) {
            $isPublic = filter_var(
                $lower,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            return $isPublic === false;
        }

        return false;
    }

    // =========================================================================
    // Asset registration
    // =========================================================================

    /**
     * Register a new WebsiteAsset for the given tenant.
     *
     * @throws \InvalidArgumentException if URL validation fails.
     */
    public function registerAsset(array $data, string $tenantId, ?string $userId = null): WebsiteAsset
    {
        $url = $data['url'] ?? '';
        $result = $this->validateUrl($url);

        if (!$result['valid']) {
            throw new \InvalidArgumentException('Invalid asset URL: ' . ($result['error'] ?? 'unknown error'));
        }

        return WebsiteAsset::create([
            'tenant_id'   => $tenantId,
            'name'        => $data['name'] ?? $result['hostname'],
            'url'         => $result['normalized_url'],
            'hostname'    => $result['hostname'],
            'scheme'      => $result['scheme'],
            'status'      => $data['status'] ?? 'active',
            'scan_policy' => $data['scan_policy'] ?? 'passive',
            'created_by'  => $userId,
        ]);
    }

    // =========================================================================
    // Ownership guard
    // =========================================================================

    /**
     * Load and verify that the asset belongs to the given tenant.
     *
     * @throws \RuntimeException('asset_not_found') if missing.
     * @throws \RuntimeException('cross_tenant_access_denied') if tenant mismatch.
     */
    public function validateOwnership(int $assetId, string $tenantId): WebsiteAsset
    {
        $asset = WebsiteAsset::find($assetId);

        if ($asset === null) {
            throw new \RuntimeException('asset_not_found');
        }

        if ($asset->tenant_id !== $tenantId) {
            throw new \RuntimeException('cross_tenant_access_denied');
        }

        return $asset;
    }

    // =========================================================================
    // Finding normalisation
    // =========================================================================

    /**
     * Accept raw check results from the Python scanner and normalise into
     * EasmFinding-compatible arrays. is_advisory is always true.
     */
    public function normalizeCheckResults(array $rawChecks, WebsiteAsset $asset): array
    {
        $normalised = [];
        $now = now()->format('Y-m-d H:i:sP');

        foreach ($rawChecks as $checkResult) {
            $findings = $checkResult['findings'] ?? [];
            foreach ($findings as $finding) {
                $normalised[] = [
                    'tenant_id'      => $asset->tenant_id,
                    'asset_id'       => $asset->id,
                    'finding_key'    => $finding['key'] ?? Str::uuid(),
                    'category'       => $finding['category'] ?? 'exposure',
                    'severity'       => $finding['severity'] ?? 'info',
                    'confidence'     => $finding['confidence'] ?? 'medium',
                    'title'          => $finding['title'] ?? $finding['key'] ?? 'Finding',
                    'description'    => $finding['description'] ?? '',
                    'evidence'       => $finding['evidence'] ?? null,
                    'recommendation' => $finding['recommendation'] ?? null,
                    'scan_policy'    => 'passive',
                    'source'         => 'easm_passive',
                    'status'         => 'open',
                    'first_seen_at'  => $now,
                    'last_seen_at'   => $now,
                    'is_advisory'    => true,
                ];
            }
        }

        return $normalised;
    }

    // =========================================================================
    // Finding persistence
    // =========================================================================

    /**
     * Upsert a normalised finding.
     * On create: set all fields including first_seen_at.
     * On update: only update last_seen_at, severity, confidence, evidence, status='open', description.
     */
    public function upsertFinding(array $normalised): EasmFinding
    {
        $match = [
            'tenant_id'   => $normalised['tenant_id'],
            'asset_id'    => $normalised['asset_id'],
            'finding_key' => $normalised['finding_key'],
        ];

        $existing = EasmFinding::where($match)->first();

        if ($existing === null) {
            return EasmFinding::create($normalised);
        }

        // Update mutable fields only — first_seen_at is never changed
        $existing->last_seen_at  = $normalised['last_seen_at'];
        $existing->severity      = $normalised['severity'];
        $existing->confidence    = $normalised['confidence'];
        $existing->evidence      = $normalised['evidence'];
        $existing->status        = 'open';
        $existing->description   = $normalised['description'];
        $existing->save();

        return $existing;
    }

    // =========================================================================
    // Scan run creation
    // =========================================================================

    /**
     * Create an append-only EasmScanRun record.
     */
    public function createScanRun(array $runData): EasmScanRun
    {
        $runData['run_id'] = $runData['run_id'] ?? 'easm-' . Str::uuid();

        return EasmScanRun::create($runData);
    }

    // =========================================================================
    // Report generation
    // =========================================================================

    /**
     * Generate a JSON-serialisable report for a completed scan.
     */
    public function generateReport(EasmScanRun $run, WebsiteAsset $asset, array $findings): array
    {
        $bySeverity  = array_fill_keys(self::SEVERITIES, 0);
        $byCategory  = array_fill_keys(self::FINDING_CATEGORIES, 0);
        $grouped     = [];

        foreach ($findings as $f) {
            $sev = $f['severity'] ?? 'info';
            $cat = $f['category'] ?? 'exposure';

            if (isset($bySeverity[$sev])) {
                $bySeverity[$sev]++;
            }
            if (isset($byCategory[$cat])) {
                $byCategory[$cat]++;
            }

            $grouped[$cat][$sev][] = $f;
        }

        $overall = 'PASS';
        if (($bySeverity['high'] ?? 0) > 0 || ($bySeverity['medium'] ?? 0) > 0) {
            $overall = 'FINDINGS';
        } elseif (count($findings) > 0) {
            $overall = 'WARN';
        }

        return [
            'asset' => [
                'id'       => $asset->id,
                'name'     => $asset->name,
                'url'      => $asset->url,
                'hostname' => $asset->hostname,
            ],
            'tenant_id'   => $asset->tenant_id,
            'scan_policy' => $run->scan_policy,
            'started_at'  => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'checks'      => $run->checks_run ?? [],
            'findings'    => $grouped,
            'summary'     => [
                'total'       => count($findings),
                'by_severity' => $bySeverity,
                'by_category' => $byCategory,
            ],
            'overall_status' => $overall,
        ];
    }

    // =========================================================================
    // Policy check list
    // =========================================================================

    /**
     * Return the list of check names for a given scan policy.
     *
     * @throws \InvalidArgumentException for unimplemented policies.
     */
    public function getScanPolicyChecks(string $policy): array
    {
        $passive = ['dns', 'tls', 'http', 'security_headers', 'cookies', 'robots_txt', 'sitemap_xml'];

        return match ($policy) {
            'passive'         => $passive,
            'safe'            => array_merge($passive, ['whois', 'certificate_transparency', 'subdomain_passive']),
            'active_approved' => array_merge($passive, ['whois', 'certificate_transparency', 'subdomain_passive', 'port_scan_top100', 'spider_passive']),
            default           => throw new \InvalidArgumentException("Unimplemented scan policy: '{$policy}'"),
        };
    }

    // =========================================================================
    // Forbidden stubs — explicitly NOT implemented
    // =========================================================================
    // The following methods must NEVER be added to this service:
    //   exploitScan(), activeScan(), runNuclei(), runZap(),
    //   brutableDirectory(), sqlInject(), xssProbe(),
    //   containTarget(), autoRemediate()
}
