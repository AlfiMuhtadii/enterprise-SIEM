<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalIntegration;
use App\Models\IdentityProviderEvent;
use App\Models\IntegrationSyncEvent;
use App\Models\NotificationEvent;
use App\Models\SaasAuditEvent;
use App\Services\EnterpriseIntegrationService;
use App\Services\SaasIdentityNormalizationService;
use Illuminate\Http\Request;

class EnterpriseIntegrationApiController extends Controller
{
    public function __construct(
        private readonly EnterpriseIntegrationService     $integrationService,
        private readonly SaasIdentityNormalizationService $normalizationService,
    ) {}

    public function getIntegrations()
    {
        return response()->json([
            'integrations' => ExternalIntegration::orderBy('integration_type')->get(),
            'advisory'     => true,
        ]);
    }

    public function getIdpEvents(Request $request)
    {
        $query = IdentityProviderEvent::query();
        if ($request->get('suspicious_only')) {
            $query->where('is_suspicious', true);
        }
        if ($provider = $request->get('provider')) {
            $query->where('provider', $provider);
        }
        if ($hours = (int) $request->get('hours', 24)) {
            $query->where('occurred_at', '>=', now()->subHours(min($hours, 168)));
        }
        return response()->json([
            'events'    => $query->orderByDesc('occurred_at')->limit(200)->get(),
            'advisory'  => true,
            'no_enforcement' => true,
        ]);
    }

    public function getSaasEvents(Request $request)
    {
        $query = SaasAuditEvent::query();
        if ($request->get('suspicious_only')) {
            $query->where('is_suspicious', true);
        }
        if ($provider = $request->get('provider')) {
            $query->where('provider', $provider);
        }
        if ($hours = (int) $request->get('hours', 24)) {
            $query->where('occurred_at', '>=', now()->subHours(min($hours, 168)));
        }
        return response()->json([
            'events'    => $query->orderByDesc('occurred_at')->limit(200)->get(),
            'advisory'  => true,
            'no_enforcement' => true,
        ]);
    }

    public function getHighRiskEvents()
    {
        return response()->json([
            'idp_high_risk'  => $this->normalizationService->getHighRiskIdentityEvents(24),
            'saas_high_risk' => $this->normalizationService->getHighRiskSaasEvents(24),
            'advisory'       => true,
            'no_auto_action' => true,
        ]);
    }

    public function getSyncHistory(ExternalIntegration $integration)
    {
        return response()->json([
            'history'  => $this->integrationService->getSyncHistory($integration),
            'advisory' => true,
        ]);
    }

    public function getNotifications()
    {
        $notifications = NotificationEvent::with('deliveries')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
        return response()->json([
            'notifications' => $notifications,
            'advisory'      => true,
            'simulated'     => true,
        ]);
    }

    public function ingestIdpEvents(Request $request, ExternalIntegration $integration)
    {
        $request->validate(['events' => 'required|array|max:500']);
        $results = $this->normalizationService->ingestBatchIdentityEvents(
            $integration, $request->input('events')
        );
        return response()->json([
            'ok'        => true,
            'results'   => $results,
            'advisory'  => true,
            'no_account_action' => true,
        ]);
    }

    public function ingestSaasEvents(Request $request, ExternalIntegration $integration)
    {
        $request->validate(['events' => 'required|array|max:500']);
        $results = $this->normalizationService->ingestBatchSaasEvents(
            $integration, $request->input('events')
        );
        return response()->json([
            'ok'        => true,
            'results'   => $results,
            'advisory'  => true,
            'no_account_action' => true,
        ]);
    }
}
