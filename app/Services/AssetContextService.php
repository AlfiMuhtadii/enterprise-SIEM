<?php

namespace App\Services;

use App\Models\AssetCriticality;
use App\Models\AssetInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * ASSET-INVENTORY: asset/CMDB catalog + advisory business-criticality tiering.
 *
 * Advisory alert-enrichment metadata only — surfaces asset context (owner,
 * environment, business-criticality tier) for analyst triage; display-only,
 * does not order or filter any incident/alert listing. Never triggers,
 * gates, or influences any response/execution path.
 */
class AssetContextService
{
    public const ADVISORY_ONLY = true;
    public const NO_AUTO_RESPONSE = true;

    public const MAX_CSV_ROWS = 5000;

    public function registerAsset(
        string $tenantId,
        string $hostname,
        ?string $ipAddress = null,
        ?string $owner = null,
        string $environment = 'production',
        string $assetType = 'unknown',
        ?string $externalId = null,
        string $source = 'manual',
        ?string $createdBy = null,
    ): AssetInventory {
        if (!in_array($environment, AssetInventory::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException("Invalid environment: {$environment}");
        }
        if (!in_array($assetType, AssetInventory::ASSET_TYPES, true)) {
            throw new InvalidArgumentException("Invalid asset type: {$assetType}");
        }
        if (!in_array($source, AssetInventory::SOURCES, true)) {
            throw new InvalidArgumentException("Invalid source: {$source}");
        }

        $externalId = $externalId ?: 'asset-' . Str::uuid();

        return AssetInventory::updateOrCreate(
            ['tenant_id' => $tenantId, 'external_id' => $externalId],
            [
                'hostname' => $hostname,
                'ip_address' => $ipAddress,
                'owner' => $owner,
                'environment' => $environment,
                'asset_type' => $assetType,
                'source' => $source,
                'created_by' => $createdBy,
            ]
        );
    }

    public function setCriticality(int $assetId, string $tier, ?string $justification, string $assessedBy): AssetCriticality
    {
        if (!in_array($tier, AssetCriticality::TIERS, true)) {
            throw new InvalidArgumentException("Invalid criticality tier: {$tier}");
        }
        $asset = AssetInventory::findOrFail($assetId);

        return AssetCriticality::updateOrCreate(
            ['asset_id' => $asset->id],
            [
                'tenant_id' => $asset->tenant_id,
                'criticality_tier' => $tier,
                'justification' => $justification,
                'assessed_by' => $assessedBy,
                'assessed_at' => now(),
            ]
        );
    }

    /**
     * Bounded CSV import (MAX_CSV_ROWS). Expected header columns: hostname,
     * ip_address, owner, environment, asset_type, external_id (all but
     * hostname optional). Unknown/invalid environment or asset_type values
     * fall back to safe defaults rather than failing the whole row.
     */
    public function importCsv(string $tenantId, string $csvPath, string $createdBy): array
    {
        if (!is_readable($csvPath)) {
            throw new InvalidArgumentException("CSV file not readable: {$csvPath}");
        }
        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Empty CSV file']];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $row = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            if ($imported + $skipped >= self::MAX_CSV_ROWS) {
                $errors[] = "Row {$row}: MAX_CSV_ROWS (" . self::MAX_CSV_ROWS . ") reached, remaining rows skipped";
                break;
            }
            $record = array_combine($header, array_pad($data, count($header), null));
            $hostname = trim((string) ($record['hostname'] ?? ''));
            if ($hostname === '') {
                $skipped++;
                $errors[] = "Row {$row}: missing hostname";
                continue;
            }
            try {
                $this->registerAsset(
                    tenantId: $tenantId,
                    hostname: $hostname,
                    ipAddress: ($record['ip_address'] ?? '') ?: null,
                    owner: ($record['owner'] ?? '') ?: null,
                    environment: in_array($record['environment'] ?? '', AssetInventory::ENVIRONMENTS, true) ? $record['environment'] : 'production',
                    assetType: in_array($record['asset_type'] ?? '', AssetInventory::ASSET_TYPES, true) ? $record['asset_type'] : 'unknown',
                    externalId: ($record['external_id'] ?? '') ?: null,
                    source: 'csv_import',
                    createdBy: $createdBy,
                );
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Row {$row}: {$e->getMessage()}";
            }
        }
        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function findAssetForAlert(string $tenantId, ?string $ipAddress = null, ?string $hostname = null): ?AssetInventory
    {
        if (!$ipAddress && !$hostname) {
            return null;
        }
        $query = AssetInventory::where('tenant_id', $tenantId);
        if ($ipAddress) {
            $match = (clone $query)->where('ip_address', $ipAddress)->first();
            if ($match) {
                return $match;
            }
        }
        if ($hostname) {
            return $query->where('hostname', $hostname)->first();
        }
        return null;
    }

    public function criticalityForAsset(int $assetId): ?string
    {
        return AssetCriticality::where('asset_id', $assetId)->value('criticality_tier');
    }

    /** Advisory context lookup for a set of IPs (e.g. an incident's alert IPs). */
    public function assetContextForIps(string $tenantId, array $ipAddresses): Collection
    {
        $ipAddresses = array_values(array_unique(array_filter($ipAddresses)));
        if (empty($ipAddresses)) {
            return collect();
        }

        return AssetInventory::where('tenant_id', $tenantId)
            ->whereIn('ip_address', $ipAddresses)
            ->with('criticality')
            ->get();
    }

    public function dashboardStats(string $tenantId): array
    {
        return [
            'total_assets' => AssetInventory::where('tenant_id', $tenantId)->count(),
            'by_environment' => AssetInventory::where('tenant_id', $tenantId)
                ->selectRaw('environment, count(*) as count')
                ->groupBy('environment')
                ->pluck('count', 'environment'),
            'by_criticality' => AssetCriticality::where('tenant_id', $tenantId)
                ->selectRaw('criticality_tier, count(*) as count')
                ->groupBy('criticality_tier')
                ->pluck('count', 'criticality_tier'),
            'crown_jewel_count' => AssetCriticality::where('tenant_id', $tenantId)
                ->where('criticality_tier', 'crown_jewel')
                ->count(),
        ];
    }
}
