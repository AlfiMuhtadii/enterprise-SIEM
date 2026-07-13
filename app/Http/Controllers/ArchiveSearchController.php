<?php

namespace App\Http\Controllers;

use App\Services\ArchiveSearchService;
use App\Services\ClickHouseArchiveSearchService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * ArchiveSearchController — read-only search over the gzip JSONL retention
 * archive (DATA-TIERING phase 2's ArchiveSearchService), closing the
 * CLI-only gap so an analyst can search archived records from the SOC
 * without shell access. No mutation, no autonomous action.
 */
class ArchiveSearchController extends Controller
{
    public function __construct(
        private readonly TenantContextAuthority $tenantAuthority,
    ) {
    }

    public function index(Request $request): View
    {
        $table = trim((string) $request->query('table', ''));
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));
        $filtersRaw = trim((string) $request->query('filters', ''));
        $limit = (int) $request->query('limit', 100) ?: 100;

        $result = null;
        $error = null;
        $tenantId = null;

        if ($table !== '') {
            try {
                // Tenant scope is authority-derived (X-Tenant-ID header validated
                // against the user's real memberships), never a free-typed query
                // param — the same boundary every other tenant-scoped SOC
                // controller in this codebase enforces. null means "no scoping"
                // (legacy-mode default / admin-unscoped) and ArchiveSearchService
                // correctly treats null as "search across all tenant archive
                // directories", unlike SiemSearchController's OpenSearch-specific
                // 'default' fallback which doesn't apply to this backing store.
                $tenantId = $this->tenantAuthority->validateAndResolve($request, Auth::user());

                $from = $fromRaw !== '' ? Carbon::parse($fromRaw) : null;
                $to = $toRaw !== '' ? Carbon::parse($toRaw) : null;
                $filters = $this->parseFilters($filtersRaw);

                // DATA-TIERING (warm tier): prefer the real, indexed
                // ClickHouse path when enabled — falls back to the gzip
                // scan on any failure (collector down, query error) so an
                // analyst's search never just breaks because the warm tier
                // happens to be unreachable; the gzip archive remains a
                // complete copy of everything regardless.
                if (config('xdr.infrastructure.clickhouse.warm_tier_enabled', false)) {
                    $warmResult = (new ClickHouseArchiveSearchService())->search(
                        table: $table,
                        tenantId: $tenantId,
                        from: $from,
                        to: $to,
                        filters: $filters,
                        limit: $limit,
                    );
                    if (empty($warmResult['warm_tier_unavailable'])) {
                        $result = $warmResult;
                    }
                }

                if ($result === null) {
                    $service = new ArchiveSearchService('storage/app/archives');
                    $result = $service->search(
                        table: $table,
                        tenantId: $tenantId,
                        from: $from,
                        to: $to,
                        filters: $filters,
                        limit: $limit,
                    );
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('archive-search.index', [
            'table' => $table,
            'tenantId' => $tenantId,
            'from' => $fromRaw,
            'to' => $toRaw,
            'filters' => $filtersRaw,
            'limit' => $limit,
            'result' => $result,
            'error' => $error,
            'maxResults' => ArchiveSearchService::MAX_RESULTS,
        ]);
    }

    /**
     * Parses "field=value,field2=value2" into an exact-match filter map,
     * the same free-text convention the CLI's --filter=field=value uses.
     */
    private function parseFilters(string $raw): array
    {
        $filters = [];
        if ($raw === '') {
            return $filters;
        }
        foreach (explode(',', $raw) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                $filters[$parts[0]] = $parts[1];
            }
        }

        return $filters;
    }
}
