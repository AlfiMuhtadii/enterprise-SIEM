<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SocReportController extends Controller
{
    public function index(): View
    {
        return view('soc.reports.index', [
            'reports' => DB::table('executive_security_reports')->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }

    public function generate(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'in:weekly,monthly']]);
        $report = $this->buildReport($data['period'], $request->user()->email);
        AuditLogger::log($request->user()->email, 'report.generate', 'executive_report', $report['report_id'], null, ['period' => $data['period']]);

        if ($request->query('format') === 'json') {
            return response()->json($report);
        }

        return view('soc.reports.show', ['report' => $report]);
    }

    public function show(string $reportId): View
    {
        $row = DB::table('executive_security_reports')->where('report_id', $reportId)->first();
        abort_if(!$row, 404);
        return view('soc.reports.show', ['report' => array_merge((array) $row, ['summary' => json_decode($row->summary, true) ?: []])]);
    }

    public function json(string $reportId): JsonResponse
    {
        $row = DB::table('executive_security_reports')->where('report_id', $reportId)->first();
        abort_if(!$row, 404);
        return response()->json(array_merge((array) $row, ['summary' => json_decode($row->summary, true) ?: []]));
    }

    public function buildReport(string $period, string $actor = 'system'): array
    {
        $end = now();
        $start = $period === 'weekly' ? now()->subWeek() : now()->subMonth();
        $incidents = DB::table('security_incidents')->whereBetween('first_seen_at', [$start, $end])->get();
        $alerts = DB::table('security_alerts')->whereBetween('detected_at', [$start, $end])->get();
        $feedback = DB::table('alert_feedback')->whereBetween('marked_at', [$start, $end])->get();

        $resolved = $incidents->whereNotNull('resolved_at');
        $mtta = $incidents->filter(fn ($row) => $row->assigned_analyst && $row->first_seen_at)
            ->map(fn ($row) => strtotime((string) $row->updated_at) - strtotime((string) $row->first_seen_at))
            ->filter(fn ($v) => $v >= 0)
            ->avg();
        $mttr = $resolved->map(fn ($row) => strtotime((string) $row->resolved_at) - strtotime((string) $row->first_seen_at))
            ->filter(fn ($v) => $v >= 0)
            ->avg();

        $summary = [
            'incident_statistics' => [
                'total' => $incidents->count(),
                'open' => $incidents->where('status', 'open')->count(),
                'resolved' => $incidents->where('status', 'resolved')->count(),
                'false_positive' => $incidents->where('status', 'false_positive')->count(),
            ],
            'mtta_seconds' => $mtta ? (int) $mtta : null,
            'mttr_seconds' => $mttr ? (int) $mttr : null,
            'top_threats' => $alerts->groupBy('alert_type')->map->count()->sortDesc()->take(10)->all(),
            'rule_performance' => $alerts->groupBy('detector_name')->map->count()->sortDesc()->take(10)->all(),
            'false_positive_trends' => $feedback->groupBy('verdict')->map->count()->all(),
            'analyst_activity' => DB::table('security_audit_trails')->whereBetween('occurred_at', [$start, $end])->get()->groupBy('actor')->map->count()->sortDesc()->take(10)->all(),
            'severity_distribution' => $incidents->groupBy('severity')->map->count()->all(),
        ];
        $reportId = 'report-'.Str::uuid();
        DB::table('executive_security_reports')->insert([
            'report_id' => $reportId,
            'period' => $period,
            'period_start' => $start,
            'period_end' => $end,
            'summary' => json_encode($summary),
            'generated_by' => $actor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'report_id' => $reportId,
            'period' => $period,
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'generated_by' => $actor,
            'summary' => $summary,
        ];
    }
}
