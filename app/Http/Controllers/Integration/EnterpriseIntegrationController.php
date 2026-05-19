<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\ExternalCaseLink;
use App\Models\ExternalIntegration;
use App\Models\IdentityProviderEvent;
use App\Models\IntegrationSyncEvent;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SaasAuditEvent;
use App\Services\EnterpriseIntegrationService;
use App\Services\NotificationService;
use App\Services\SaasIdentityNormalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnterpriseIntegrationController extends Controller
{
    public function __construct(
        private readonly EnterpriseIntegrationService      $integrationService,
        private readonly NotificationService               $notificationService,
        private readonly SaasIdentityNormalizationService  $normalizationService,
    ) {}

    public function dashboard()
    {
        $integrations = ExternalIntegration::orderBy('integration_type')->orderBy('name')->get();
        $recentSyncs  = IntegrationSyncEvent::orderByDesc('created_at')->limit(20)->get();
        $stats = [
            'total'         => $integrations->count(),
            'active'        => $integrations->where('status', 'active')->count(),
            'idp_events'    => IdentityProviderEvent::count(),
            'saas_events'   => SaasAuditEvent::count(),
            'notifications' => NotificationEvent::count(),
        ];
        return view('integrations.dashboard', compact('integrations', 'recentSyncs', 'stats'));
    }

    public function integrationRegistry()
    {
        $integrations = ExternalIntegration::orderBy('integration_type')->get();
        return view('integrations.registry', compact('integrations'));
    }

    public function identityProviderFeed()
    {
        $events = IdentityProviderEvent::orderByDesc('occurred_at')->limit(200)->get();
        $suspicious = IdentityProviderEvent::where('is_suspicious', true)->count();
        return view('integrations.idp-feed', compact('events', 'suspicious'));
    }

    public function saasAuditFeed()
    {
        $events = SaasAuditEvent::orderByDesc('occurred_at')->limit(200)->get();
        $suspicious = SaasAuditEvent::where('is_suspicious', true)->count();
        return view('integrations.saas-audit', compact('events', 'suspicious'));
    }

    public function notificationCenter()
    {
        $notifications = NotificationEvent::with('deliveries')->orderByDesc('created_at')->limit(100)->get();
        $pending       = NotificationEvent::where('requires_analyst_approval', true)->count();
        return view('integrations.notification-center', compact('notifications', 'pending'));
    }

    public function caseLinks()
    {
        $links = ExternalCaseLink::orderByDesc('created_at')->limit(200)->get();
        return view('integrations.case-links', compact('links'));
    }

    public function syncHistory()
    {
        $syncs = IntegrationSyncEvent::with('integration')->orderByDesc('started_at')->limit(200)->get();
        return view('integrations.sync-history', compact('syncs'));
    }

    // -----------------------------------------------------------------------
    // Mutation — analyst-initiated only, advisory posture enforced
    // -----------------------------------------------------------------------

    public function registerIntegration(Request $request)
    {
        $request->validate([
            'integration_type' => 'required|in:idp,saas,ticketing,notification',
            'provider'         => 'required|string|max:100',
            'name'             => 'required|string|max:255',
        ]);

        $integration = $this->integrationService->registerIntegration($request->only(
            'integration_type', 'provider', 'name', 'config', 'field_mappings',
            'inbound_enabled', 'outbound_enabled'
        ));

        return response()->json([
            'ok'          => true,
            'integration' => $integration,
            'advisory'    => true,
        ]);
    }

    public function activateIntegration(ExternalIntegration $integration)
    {
        $updated = $this->integrationService->activateIntegration($integration);
        return response()->json(['ok' => true, 'integration' => $updated]);
    }

    public function disableIntegration(ExternalIntegration $integration)
    {
        $updated = $this->integrationService->disableIntegration($integration);
        return response()->json(['ok' => true, 'integration' => $updated]);
    }

    public function linkCase(Request $request, ExternalIntegration $integration)
    {
        $request->validate([
            'investigation_id'   => 'required|string',
            'external_ticket_id' => 'required|string|max:200',
            'link_direction'     => 'in:soc_to_external,external_to_soc',
        ]);

        $link = $this->integrationService->linkCase(
            $request->input('investigation_id'),
            $integration,
            $request->all(),
            Auth::id()
        );

        return response()->json([
            'ok'           => true,
            'link'         => $link,
            'auto_closed'  => false,   // hard constraint: always false
            'advisory'     => true,
        ]);
    }

    public function sendNotification(Request $request, ExternalIntegration $integration)
    {
        $request->validate([
            'notification_type' => 'required|in:alert_fired,incident_created,escalation,sla_breach,hunt_completed',
            'severity'          => 'required|in:low,medium,high,critical',
            'channel'           => 'required|in:slack,webhook,pagerduty,email,teams',
            'subject'           => 'required|string|max:255',
        ]);

        $notification = $this->notificationService->createNotification(
            $integration, $request->all(), Auth::id()
        );
        $delivery = $this->notificationService->simulateDelivery($notification);

        return response()->json([
            'ok'           => true,
            'notification' => $notification,
            'delivery'     => $delivery,
            'simulated'    => true,  // hard constraint: always simulated in advisory mode
            'advisory'     => true,
        ]);
    }
}
