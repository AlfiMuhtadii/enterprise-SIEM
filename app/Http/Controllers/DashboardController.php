<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $since = now()->subMinutes(60);

        $totals = [
            'events' => DB::table('security_events')->count(),
            'alerts' => DB::table('security_alerts')->count(),
            'responses' => DB::table('security_responses')->count(),
            'fresh_alerts' => DB::table('security_alerts')->where('detected_at', '>=', $since)->count(),
        ];

        $latestAlerts = DB::table('security_alerts')
            ->select('detected_at', 'alert_type', 'severity', 'ip', 'score')
            ->orderByDesc('detected_at')
            ->limit(8)
            ->get();

        $topTypes = DB::table('security_alerts')
            ->select('alert_type', DB::raw('count(*) as total'))
            ->groupBy('alert_type')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        return view('dashboard', [
            'totals' => $totals,
            'latestAlerts' => $latestAlerts,
            'topTypes' => $topTypes,
        ]);
    }
}
