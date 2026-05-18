<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\SecurityHardeningEvent;
use App\Services\SecretsValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SecurityHardeningController extends Controller
{
    public function __construct(private SecretsValidationService $secrets) {}

    public function index(): View
    {
        $validation = $this->secrets->validate();

        $recentEvents = SecurityHardeningEvent::orderByDesc('occurred_at')
            ->limit(50)
            ->get();

        $eventCounts = SecurityHardeningEvent::select('event_type', DB::raw('count(*) as cnt'))
            ->groupBy('event_type')
            ->pluck('cnt', 'event_type')
            ->all();

        $auditIntegrity = $this->auditIntegrityStatus();

        $serviceAuthStatus = $this->serviceAuthStatus();

        return view('security.hardening', compact(
            'validation',
            'recentEvents',
            'eventCounts',
            'auditIntegrity',
            'serviceAuthStatus'
        ));
    }

    private function auditIntegrityStatus(): array
    {
        $tables = [
            'export_audit_logs'        => 'Export audit log',
            'investigation_events'     => 'Investigation events',
            'response_plan_approvals'  => 'Response plan approvals',
            'security_hardening_events'=> 'Security hardening events',
        ];

        $status = [];
        foreach ($tables as $table => $label) {
            $exists = false;
            $count  = 0;
            try {
                $count  = DB::table($table)->count();
                $exists = true;
            } catch (\Throwable) {
                // table not migrated yet in test env
            }
            $status[] = [
                'table'  => $table,
                'label'  => $label,
                'exists' => $exists,
                'count'  => $count,
                'ok'     => $exists,
            ];
        }

        return $status;
    }

    private function serviceAuthStatus(): array
    {
        $ingestSecret   = env('XDR_INGEST_SECRET', '');
        $internalSecret = env('XDR_INTERNAL_AUTH_SECRET', '');

        return [
            [
                'service'    => 'ingestion-gateway',
                'auth_type'  => 'HMAC-SHA256 (X-XDR-Signature)',
                'configured' => $ingestSecret !== '' && $ingestSecret !== 'dev-secret-change-me',
                'dev_default'=> $ingestSecret === 'dev-secret-change-me',
            ],
            [
                'service'    => 'internal-api',
                'auth_type'  => 'Time-bounded HMAC token (X-Internal-Service-Token)',
                'configured' => $internalSecret !== '',
                'dev_default'=> false,
            ],
            [
                'service'    => 'normalizer-worker',
                'auth_type'  => 'Optional internal token (XDR_NORMALIZER_INTERNAL_TOKEN)',
                'configured' => env('XDR_NORMALIZER_INTERNAL_TOKEN', '') !== '',
                'dev_default'=> false,
            ],
            [
                'service'    => 'alert-writer-service',
                'auth_type'  => 'Optional internal token (XDR_ALERT_WRITER_INTERNAL_TOKEN)',
                'configured' => env('XDR_ALERT_WRITER_INTERNAL_TOKEN', '') !== '',
                'dev_default'=> false,
            ],
        ];
    }
}
