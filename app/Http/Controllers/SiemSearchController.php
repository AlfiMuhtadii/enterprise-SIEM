<?php

namespace App\Http\Controllers;

use App\Services\SiemSearchService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * SiemSearchController — read-only free-form search over raw telemetry/alerts.
 *
 * No mutation, no autonomous action. Tenant-scoped and redacted.
 */
class SiemSearchController extends Controller
{
    public function __construct(
        private readonly SiemSearchService $service,
        private readonly TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        $query = trim((string) $request->query('q', ''));
        $result = null;
        $error = null;

        if ($query !== '') {
            try {
                $result = $this->service->search(
                    $tenantId,
                    $query,
                    $request->query('max_results') !== null ? (int) $request->query('max_results') : null,
                    $request->query('window_days') !== null ? (int) $request->query('window_days') : null,
                );
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return view('siem-search.index', [
            'tenantId' => $tenantId,
            'query' => $query,
            'result' => $result,
            'error' => $error,
            'maxResults' => SiemSearchService::MAX_RESULTS,
            'windowDays' => SiemSearchService::MAX_QUERY_WINDOW_DAYS,
            'minQueryLength' => SiemSearchService::MIN_QUERY_LENGTH,
        ]);
    }

    private function resolveTenantId(Request $request): string
    {
        return $this->tenantAuthority->validateAndResolve($request, Auth::user()) ?? 'default';
    }
}
