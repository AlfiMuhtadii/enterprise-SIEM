<?php

namespace App\Http\Controllers;

use App\Services\AlertMitreService;
use App\Services\TenantContextAuthority;
use App\Support\SecurityResponsePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SecurityAlertController extends Controller
{
    public function __construct(private readonly TenantContextAuthority $tenantAuthority) {}

    public function index(Request $request, AlertMitreService $mitre): View
    {
        $minutes  = max(1, min(1440, (int) $request->query('minutes', 60)));
        $since    = now()->subMinutes($minutes);
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);

        $scopeAlerts = fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q;

        $summary = $scopeAlerts(DB::table('security_alerts'))
            ->select('alert_type', DB::raw('count(*) as total'), DB::raw('max(detected_at) as latest_at'))
            ->where('detected_at', '>=', $since)
            ->groupBy('alert_type')
            ->orderByDesc('total')
            ->get();

        $severity = $scopeAlerts(DB::table('security_alerts'))
            ->select('severity', DB::raw('count(*) as total'))
            ->where('detected_at', '>=', $since)
            ->groupBy('severity')
            ->orderByDesc('total')
            ->get();

        $topIps = $scopeAlerts(DB::table('security_alerts'))
            ->select('ip', DB::raw('count(*) as total'), DB::raw('max(score) as max_score'))
            ->where('detected_at', '>=', $since)
            ->whereNotNull('ip')
            ->groupBy('ip')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $alerts = $scopeAlerts(DB::table('security_alerts'))
            ->select('detected_at', 'alert_type', 'severity', 'ip', 'score', 'model_label', 'evidence', 'mitre_tactic', 'mitre_technique_id', 'mitre_technique_name')
            ->where('detected_at', '>=', $since)
            ->orderByDesc('detected_at')
            ->limit(80)
            ->get();

        $responses = DB::table('security_responses')
            ->select('created_at_event', 'action_type', 'target_type', 'target_id', 'status', 'severity', 'reason')
            ->where('created_at_event', '>=', $since)
            ->orderByDesc('created_at_event')
            ->limit(40)
            ->get();

        return view('security.alerts', [
            'mitreMap' => collect($mitre->mappedAlertTypes())
                ->mapWithKeys(fn ($t) => [$t => $mitre->lookup($t)])
                ->all(),
            'minutes' => $minutes,
            'summary' => $summary,
            'severity' => $severity,
            'topIps' => $topIps,
            'alerts' => $alerts,
            'responses' => $responses,
            'policyEntries' => [
                'THROTTLE_LOGIN_IP' => SecurityResponsePolicy::activeEntries('throttle_ips'),
                'FORCE_CAPTCHA_IP' => SecurityResponsePolicy::activeEntries('captcha_ips'),
                'REVOKE_SESSION_USER' => SecurityResponsePolicy::activeEntries('revoke_user_ids'),
            ],
            'totals' => [
                'alerts' => $alerts->count(),
                'responses' => $responses->count(),
                'types' => $summary->count(),
                'ips' => $topIps->count(),
            ],
        ]);
    }
}
