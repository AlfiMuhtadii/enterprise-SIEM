<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SocExportController extends Controller
{
    public function download(Request $request, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['jsonl', 'siem', 'stix'], true), 404);
        $limit = (int) config('soc.export_max_rows', 500);
        $alerts = DB::table('security_alerts')
            ->whereRaw('COALESCE(is_suppressed, false)=false')
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get();

        AuditLogger::log($request->user()->email, 'export.download', 'export', $format, null, ['count' => $alerts->count()]);

        $filename = 'soc-alerts-'.$format.'-'.now()->format('Ymd-His').($format === 'stix' ? '.json' : '.jsonl');

        return response()->streamDownload(function () use ($alerts, $format) {
            if ($format === 'stix') {
                echo json_encode([
                    'type' => 'bundle',
                    'id' => 'bundle--detector-soc-export',
                    'objects' => $alerts->map(fn ($a) => [
                        'type' => 'indicator',
                        'id' => 'indicator--'.substr($a->alert_id, 0, 8).'-'.substr($a->alert_id, 8, 4).'-4000-8000-'.substr($a->alert_id, -12),
                        'name' => $a->alert_type,
                        'description' => $a->severity.' alert linked to '.$a->incident_id,
                        'pattern' => "[ipv4-addr:value = '".($a->ip ?: '0.0.0.0')."']",
                        'pattern_type' => 'stix',
                    ])->values(),
                ], JSON_PRETTY_PRINT);
                return;
            }
            foreach ($alerts as $alert) {
                echo json_encode($alert)."\n";
            }
        }, $filename);
    }

    public function webhookTest(Request $request, string $target): RedirectResponse
    {
        abort_unless(in_array($target, ['webhook', 'slack', 'discord'], true), 404);
        $data = $request->validate(['url' => ['required', 'url']]);
        $payload = [
            'text' => 'SOC export test from Detector',
            'content' => 'SOC export test from Detector',
            'source' => 'detector-soc',
            'target' => $target,
            'sent_at' => now()->toIso8601String(),
        ];
        $secret = config('soc.webhook_secret');
        $headers = [];
        if ($secret) {
            $headers['X-Detector-Signature'] = hash_hmac('sha256', json_encode($payload), $secret);
        }
        $response = Http::withHeaders($headers)->timeout(8)->post($data['url'], $payload);
        AuditLogger::log($request->user()->email, 'export.webhook_test', 'export', $target, null, ['status' => $response->status()]);

        return back()->with('status', strtoupper($target).' test sent: HTTP '.$response->status());
    }
}
