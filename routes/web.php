<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\BasicPageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OpsHealthController;
use App\Http\Controllers\SecurityAlertController;
use App\Http\Controllers\SocApiController;
use App\Http\Controllers\SocAgentController;
use App\Http\Controllers\SocAiController;
use App\Http\Controllers\SocDashboardController;
use App\Http\Controllers\SocEndpointTimelineController;
use App\Http\Controllers\SocExportController;
use App\Http\Controllers\SocForensicController;
use App\Http\Controllers\SocHuntController;
use App\Http\Controllers\SocIncidentController;
use App\Http\Controllers\SocKnowledgeBaseController;
use App\Http\Controllers\SocPlaybookController;
use App\Http\Controllers\SocReportController;
use App\Http\Controllers\SocRuleController;
use App\Http\Controllers\SocResponseController;
use App\Http\Controllers\SocThreatIntelController;
use App\Http\Controllers\SocTuningController;
use App\Http\Controllers\Scenario\ScenarioLibraryController;
use App\Http\Controllers\Scenario\ScenarioRunController;
use App\Http\Controllers\Scenario\ScenarioReplayController;
use App\Http\Controllers\Scenario\ScenarioReportController;
use App\Http\Controllers\Scenario\ScenarioEvidenceController;
use App\Http\Controllers\Trace\TraceInvestigationController;
use App\Http\Controllers\Api\TraceApiController;
use App\Http\Controllers\Detection\DetectionRuleController;
use App\Http\Controllers\Endpoint\EndpointController;
use App\Http\Controllers\Entity\EntityController;
use App\Http\Controllers\Entity\EntityRiskController;
use App\Http\Controllers\Api\EntityApiController;
use App\Http\Controllers\Api\EntityRiskApiController;
use App\Http\Controllers\Investigation\InvestigationController;
use App\Http\Controllers\Api\InvestigationApiController;
use App\Http\Controllers\Response\ResponsePlanController;
use App\Http\Controllers\Api\ResponsePlanApiController;
use App\Http\Controllers\Export\ExportController;
use App\Http\Controllers\Api\ExportApiController;
use App\Http\Controllers\Security\SecurityHardeningController;
use App\Http\Controllers\Security\ThreatHuntController;
use App\Http\Controllers\Api\ThreatHuntApiController;
use App\Http\Controllers\Resilience\ResilienceController;
use App\Http\Controllers\Api\EndpointAgentApiController;
use App\Http\Controllers\Endpoint\EndpointAgentController;
use App\Http\Controllers\Endpoint\EndpointAnalyticsController;
use App\Http\Controllers\Endpoint\EndpointBehavioralController;
use App\Http\Controllers\Endpoint\EndpointResponseController;
use App\Http\Controllers\Api\EndpointBehavioralApiController;
use App\Http\Controllers\Investigation\CrossDomainController;
use App\Http\Controllers\Api\CrossDomainApiController;
use App\Http\Controllers\Response\ActiveResponseController;
use App\Http\Controllers\Api\ActiveResponseApiController;
use App\Http\Controllers\Endpoint\EndpointStreamController;
use App\Http\Controllers\Api\EndpointStreamApiController;
use App\Http\Controllers\Resilience\OperationsController;
use App\Http\Controllers\Api\OperationsApiController;
use App\Http\Controllers\Network\NetworkAnalyticsController;
use App\Http\Controllers\Api\NetworkAnalyticsApiController;
use App\Http\Controllers\Soc\SocWorkflowController;
use App\Http\Controllers\Api\SocWorkflowApiController;
use App\Http\Controllers\Integration\EnterpriseIntegrationController;
use App\Http\Controllers\Api\EnterpriseIntegrationApiController;
use App\Http\Controllers\UEBA\UEBAController;
use App\Http\Controllers\Api\UEBAApiController;
use App\Http\Controllers\Endpoint\EndpointFleetController;
use App\Http\Controllers\Api\EndpointFleetApiController;
use App\Http\Controllers\Endpoint\EndpointTelemetryController;
use App\Http\Controllers\Detection\DetectionLifecycleController;
use App\Http\Controllers\Investigation\AdvancedHuntingController;
use App\Http\Controllers\Soar\SoarOrchestrationController;
use App\Http\Controllers\Reliability\DistributedReliabilityController;
use App\Http\Controllers\Governance\ComplianceGovernanceController;
use App\Http\Middleware\InternalServiceAuthMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [BasicPageController::class, 'welcome']);

Route::get('/health/live', [OpsHealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [OpsHealthController::class, 'ready'])->name('health.ready');
Route::get('/health/services/{service}', [OpsHealthController::class, 'service'])->name('health.service');

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/search', [BasicPageController::class, 'search']);

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/admin', [BasicPageController::class, 'admin'])->middleware(['auth', 'admin']);

Route::get('/security/alerts', [SecurityAlertController::class, 'index'])
    ->middleware(['auth', 'admin'])
    ->name('security.alerts');

Route::middleware(['auth', 'soc:dashboard.view'])->group(function () {
    Route::get('/soc', SocDashboardController::class)->name('soc.dashboard');
    Route::get('/soc/hunts', [SocHuntController::class, 'index'])->name('soc.hunts');
    Route::get('/soc/hunts/export', [SocHuntController::class, 'export'])->name('soc.hunts.export');
    Route::post('/soc/hunts/save', [SocHuntController::class, 'save'])->name('soc.hunts.save');
    Route::get('/soc/tuning', [SocTuningController::class, 'index'])->name('soc.tuning');
    Route::get('/soc/threat-intel', [SocThreatIntelController::class, 'index'])->name('soc.threat-intel');
    Route::get('/soc/knowledge', [SocKnowledgeBaseController::class, 'index'])->name('soc.knowledge');
    Route::get('/soc/reports', [SocReportController::class, 'index'])->name('soc.reports');
    Route::get('/soc/reports/{reportId}', [SocReportController::class, 'show'])->name('soc.reports.show');
    Route::get('/soc/reports/{reportId}/json', [SocReportController::class, 'json'])->name('soc.reports.json');
    Route::get('/soc/endpoints/{hostId}', [SocEndpointTimelineController::class, 'show'])->name('soc.endpoints.timeline');
    Route::get('/soc/incidents/{incidentId}', [SocIncidentController::class, 'show'])->name('soc.incidents.show');
    Route::middleware('throttle:soc-api')->group(function () {
        Route::get('/soc/api/stats', [SocApiController::class, 'stats'])->name('soc.api.stats');
        Route::get('/soc/api/incidents', [SocApiController::class, 'incidents'])->name('soc.api.incidents');
        Route::get('/soc/api/incidents/{incidentId}', [SocApiController::class, 'incident'])->name('soc.api.incident');
        Route::get('/soc/api/alerts', [SocApiController::class, 'alerts'])->name('soc.api.alerts');
        Route::get('/soc/api/benchmarks', [SocApiController::class, 'benchmarks'])->name('soc.api.benchmarks');
        Route::get('/soc/api/agents', [SocApiController::class, 'agents'])->name('soc.api.agents');
        Route::get('/soc/api/metrics', [OpsHealthController::class, 'metrics'])->name('soc.api.metrics');
    });
});

Route::middleware(['auth', 'soc:audit.view'])->group(function () {
    Route::get('/soc/api/audit', [SocApiController::class, 'audit'])->middleware('throttle:soc-api')->name('soc.api.audit');
});

Route::middleware(['auth', 'soc:workflow.execute'])->group(function () {
    Route::post('/soc/api/incidents/{incidentId}/workflow', [SocApiController::class, 'workflow'])->middleware('throttle:soc-api')->name('soc.api.workflow');
    Route::post('/soc/incidents/{incidentId}', [SocIncidentController::class, 'update'])->name('soc.incidents.update');
    Route::post('/soc/incidents/{incidentId}/ai', [SocAiController::class, 'generate'])->name('soc.ai.generate');
    Route::post('/soc/ai/{suggestionId}/review', [SocAiController::class, 'review'])->name('soc.ai.review');
    Route::post('/soc/knowledge', [SocKnowledgeBaseController::class, 'store'])->name('soc.knowledge.store');
    Route::post('/soc/incidents/{incidentId}/playbooks', [SocPlaybookController::class, 'store'])->name('soc.playbooks.store');
    Route::post('/soc/playbook-tasks/{taskId}', [SocPlaybookController::class, 'updateTask'])->name('soc.playbooks.tasks.update');
    Route::post('/soc/tuning/alerts/{alertId}', [SocTuningController::class, 'mark'])->name('soc.tuning.mark');
    Route::post('/soc/tuning/suppressions', [SocTuningController::class, 'suppress'])->name('soc.tuning.suppress');
    Route::post('/soc/tuning/suppressions/apply', [SocTuningController::class, 'applySuppressions'])->name('soc.tuning.suppress.apply');
    Route::post('/soc/tuning/notes', [SocTuningController::class, 'note'])->name('soc.tuning.notes');
    Route::post('/soc/threat-intel/iocs', [SocThreatIntelController::class, 'store'])->name('soc.threat-intel.iocs.store');
    Route::post('/soc/threat-intel/import', [SocThreatIntelController::class, 'import'])->name('soc.threat-intel.import');
    Route::post('/soc/threat-intel/enrich', [SocThreatIntelController::class, 'enrich'])->name('soc.threat-intel.enrich');
    Route::post('/soc/threat-intel/lookup', [SocThreatIntelController::class, 'lookup'])->name('soc.threat-intel.lookup');
    Route::post('/soc/threat-intel/external-feed', [SocThreatIntelController::class, 'externalFeed'])->name('soc.threat-intel.external-feed');
    Route::post('/soc/reports/generate', [SocReportController::class, 'generate'])->name('soc.reports.generate');
});

Route::middleware(['auth', 'soc:agents.manage'])->group(function () {
    Route::get('/soc/agents', [SocAgentController::class, 'index'])->name('soc.agents');
    Route::post('/soc/agents/policies', [SocAgentController::class, 'storePolicy'])->name('soc.agents.policies.store');
    Route::post('/soc/agents/{agentId}/policy', [SocAgentController::class, 'assignPolicy'])->name('soc.agents.assign-policy');
    Route::post('/soc/agents/{agentId}/commands', [SocAgentController::class, 'queueCommand'])->name('soc.agents.command');
});

Route::middleware(['auth', 'soc:workflow.execute'])->group(function () {
    Route::post('/soc/responses', [SocResponseController::class, 'recommend'])->name('soc.responses.recommend');
    Route::post('/soc/responses/{responseId}/decision', [SocResponseController::class, 'decide'])->name('soc.responses.decide');
    Route::post('/soc/forensics', [SocForensicController::class, 'request'])->name('soc.forensics.request');
    Route::post('/soc/forensics/{jobId}/decision', [SocForensicController::class, 'decide'])->name('soc.forensics.decide');
});

Route::middleware(['auth', 'soc:rules.manage'])->group(function () {
    Route::get('/soc/rules', [SocRuleController::class, 'index'])->name('soc.rules');
    Route::post('/soc/rules/{ruleId}', [SocRuleController::class, 'update'])->name('soc.rules.update');
});

Route::middleware(['auth', 'soc:exports.run'])->group(function () {
    Route::get('/soc/exports/{format}', [SocExportController::class, 'download'])->middleware('throttle:soc-api')->name('soc.exports.download');
    Route::post('/soc/exports/test/{target}', [SocExportController::class, 'webhookTest'])->middleware('throttle:soc-api')->name('soc.exports.test');
});

// Scenario Runner — view access
Route::middleware(['auth', 'soc:scenario.view'])->group(function () {
    Route::get('/scenario', [ScenarioLibraryController::class, 'index'])->name('scenario.library');
    Route::get('/scenario/{scenarioId}', [ScenarioLibraryController::class, 'show'])->name('scenario.library.show');
    Route::get('/scenario/runs/timeline/{runId}', [ScenarioRunController::class, 'timeline'])->name('scenario.runs.timeline');
    Route::get('/scenario/runs/active', [ScenarioRunController::class, 'index'])->name('scenario.runs.active');
    Route::get('/scenario/runs/{runId}/evidence', [ScenarioRunController::class, 'runEvidence'])->name('scenario.runs.evidence');
    Route::get('/scenario/runs/{runId}/report', [ScenarioRunController::class, 'runReport'])->name('scenario.runs.report');
    Route::get('/scenario/reports', [ScenarioReportController::class, 'index'])->name('scenario.reports');
    Route::get('/scenario/replay', [ScenarioReplayController::class, 'index'])->name('scenario.replay');
});

// Scenario Runner — run access
Route::middleware(['auth', 'soc:scenario.run'])->group(function () {
    Route::post('/scenario/runs', [ScenarioRunController::class, 'store'])->name('scenario.runs.store');
    Route::post('/scenario/runs/{runId}/stop', [ScenarioRunController::class, 'stop'])->name('scenario.runs.stop');
});

// Scenario Runner — replay access
Route::middleware(['auth', 'soc:scenario.replay'])->group(function () {
    Route::post('/scenario/replay', [ScenarioReplayController::class, 'store'])->name('scenario.replay.store');
});

// Scenario Runner — export access
Route::middleware(['auth', 'soc:scenario.export'])->group(function () {
    Route::get('/scenario/reports/{runId}/export', [ScenarioReportController::class, 'export'])->name('scenario.reports.export');
});

// Scenario Runner — evidence view
Route::middleware(['auth', 'soc:scenario.evidence.view'])->group(function () {
    Route::get('/scenario/evidence', [ScenarioEvidenceController::class, 'index'])->name('scenario.evidence');
});

// Endpoint Inventory & Timeline (shadow-only, no active promotion)
Route::middleware(['auth', 'soc:dashboard.view'])->group(function () {
    Route::get('/endpoint', [EndpointController::class, 'index'])->name('endpoint.index');
    Route::get('/endpoint/{agentId}', [EndpointController::class, 'show'])->name('endpoint.show');
    Route::get('/endpoint/{traceId}/trace', [EndpointController::class, 'traceView'])->name('endpoint.trace');
    Route::get('/api/endpoint/health', [EndpointController::class, 'health'])->name('endpoint.health');
});

// Endpoint Agent Hardening — enrollment inventory, detail, config policy
// Literal paths must come before wildcard {agentId} routes
Route::middleware(['auth', 'soc:agents.manage'])->group(function () {
    Route::get('/endpoint-agents', [EndpointAgentController::class, 'inventory'])->name('endpoint.agent.inventory');
    // Response queue & command routes (literal — must precede {agentId} wildcard)
    Route::get('/endpoint-agents/response-queue', [EndpointResponseController::class, 'queue'])->name('endpoint.response.queue');
    Route::get('/endpoint-agents/commands/{commandId}', [EndpointResponseController::class, 'show'])->name('endpoint.response.show');
    Route::post('/endpoint-agents/commands', [EndpointResponseController::class, 'store'])->name('endpoint.response.store');
    Route::post('/endpoint-agents/commands/{commandId}/submit', [EndpointResponseController::class, 'submit'])->name('endpoint.response.submit');
    Route::post('/endpoint-agents/commands/{commandId}/approve', [EndpointResponseController::class, 'approve'])->name('endpoint.response.approve');
    Route::post('/endpoint-agents/commands/{commandId}/reject', [EndpointResponseController::class, 'reject'])->name('endpoint.response.reject');
    Route::post('/endpoint-agents/commands/{commandId}/cancel', [EndpointResponseController::class, 'cancel'])->name('endpoint.response.cancel');
    Route::post('/endpoint-agents/commands/{commandId}/dispatch', [EndpointResponseController::class, 'dispatch'])->name('endpoint.response.dispatch');
    // Wildcard routes after literals
    Route::get('/endpoint-agents/{agentId}', [EndpointAgentController::class, 'detail'])->name('endpoint.agent.detail');
    Route::get('/endpoint-agents/{agentId}/config', [EndpointAgentController::class, 'configPolicy'])->name('endpoint.agent.config');
    Route::post('/endpoint-agents/{agentId}/config', [EndpointAgentController::class, 'updateConfig'])->name('endpoint.agent.config.update');
});

// Endpoint Agent API — no session/CSRF (agent-facing endpoints)
Route::prefix('api/agents')->group(function () {
    Route::post('/enroll', [EndpointAgentApiController::class, 'enroll'])->name('api.agents.enroll');
    Route::post('/{agentId}/heartbeat', [EndpointAgentApiController::class, 'heartbeat'])->name('api.agents.heartbeat');
    Route::get('/{agentId}/config', [EndpointAgentApiController::class, 'agentConfig'])->name('api.agents.config');
    // Command lifecycle API (agent polling + ack + result)
    Route::get('/{agentId}/commands', [EndpointAgentApiController::class, 'pollCommands'])->name('api.agents.commands.poll');
    Route::post('/{agentId}/commands/{commandId}/ack', [EndpointAgentApiController::class, 'acknowledgeCommand'])->name('api.agents.commands.ack');
    Route::post('/{agentId}/commands/{commandId}/result', [EndpointAgentApiController::class, 'commandResult'])->name('api.agents.commands.result');
    // Behavioral snapshot API (shadow-only visibility)
    Route::post('/{agentId}/behavioral-snapshot', [EndpointBehavioralApiController::class, 'storeSnapshot'])->name('api.agents.behavioral.snapshot');
});

// Endpoint Behavioral Analytics — advisory-only, shadow-mode
Route::middleware(['auth', 'soc:agents.manage'])->group(function () {
    Route::get('/endpoint-agents/{agentId}/analytics', [EndpointAnalyticsController::class, 'findingsDashboard'])->name('endpoint.analytics.dashboard');
    Route::get('/endpoint-agents/{agentId}/analytics/chains', [EndpointAnalyticsController::class, 'executionChainTimeline'])->name('endpoint.analytics.chains');
    Route::get('/endpoint-agents/{agentId}/analytics/beacon', [EndpointAnalyticsController::class, 'beaconPatternView'])->name('endpoint.analytics.beacon');
    Route::get('/endpoint-agents/{agentId}/analytics/rare-parent-child', [EndpointAnalyticsController::class, 'rareParentChildView'])->name('endpoint.analytics.rare-parent-child');
    Route::get('/endpoint-agents/{agentId}/analytics/persistence-correlation', [EndpointAnalyticsController::class, 'persistenceCorrelationView'])->name('endpoint.analytics.persistence-correlation');
});

// Endpoint Behavioral Visibility — shadow-only investigation views
Route::middleware(['auth', 'soc:agents.manage'])->group(function () {
    Route::get('/endpoint-agents/{agentId}/activity', [EndpointBehavioralController::class, 'activityTimeline'])->name('endpoint.behavioral.activity');
    Route::get('/endpoint-agents/{agentId}/process-tree', [EndpointBehavioralController::class, 'processTree'])->name('endpoint.behavioral.process-tree');
    Route::get('/endpoint-agents/{agentId}/persistence', [EndpointBehavioralController::class, 'persistenceInventory'])->name('endpoint.behavioral.persistence');
    Route::get('/endpoint-agents/{agentId}/process-network', [EndpointBehavioralController::class, 'processNetwork'])->name('endpoint.behavioral.process-network');
    Route::get('/endpoint-agents/{agentId}/long-lived', [EndpointBehavioralController::class, 'longLivedProcesses'])->name('endpoint.behavioral.long-lived');
});


// Detection Rule Governance
Route::middleware(['auth', 'soc:rules.govern'])->group(function () {
    Route::get('/detection', [DetectionRuleController::class, 'index'])->name('detection.index');
    // Lifecycle routes BEFORE the {ruleId} catch-all to avoid route conflict
    Route::prefix('detection/lifecycle')->group(function () {
        Route::get('/',                 [DetectionLifecycleController::class, 'lifecycleOverview'])->name('detection.lifecycle.overview');
        Route::get('/versions/{ruleId}',[DetectionLifecycleController::class, 'versionHistory'])->name('detection.lifecycle.versions');
        Route::get('/replay-packs',     [DetectionLifecycleController::class, 'replayPacks'])->name('detection.lifecycle.replay-packs');
        Route::get('/replay-results',   [DetectionLifecycleController::class, 'replayResults'])->name('detection.lifecycle.replay-results');
        Route::get('/false-positives',  [DetectionLifecycleController::class, 'falsePositives'])->name('detection.lifecycle.false-positives');
        Route::get('/suppressions',     [DetectionLifecycleController::class, 'suppressions'])->name('detection.lifecycle.suppressions');
        Route::get('/attack-map',       [DetectionLifecycleController::class, 'attackMap'])->name('detection.lifecycle.attack-map');
        Route::get('/promotions',       [DetectionLifecycleController::class, 'promotions'])->name('detection.lifecycle.promotions');
        Route::get('/quality',          [DetectionLifecycleController::class, 'qualityDashboard'])->name('detection.lifecycle.quality');
    });
    Route::get('/detection/{ruleId}', [DetectionRuleController::class, 'show'])->name('detection.show');
    Route::post('/detection/{ruleId}/promote', [DetectionRuleController::class, 'promote'])->name('detection.promote');
    Route::post('/detection/{ruleId}/notes', [DetectionRuleController::class, 'storeNote'])->name('detection.notes.store');
    Route::post('/detection/{ruleId}/checklist', [DetectionRuleController::class, 'updateChecklist'])->name('detection.checklist.update');
    Route::post('/detection/{ruleId}/suppression', [DetectionRuleController::class, 'updateSuppression'])->name('detection.suppression.update');
    Route::post('/detection/{ruleId}/replay-validated', [DetectionRuleController::class, 'markReplayValidated'])->name('detection.replay.mark');
    Route::get('/detection/{ruleId}/gates', [DetectionRuleController::class, 'gatesApi'])->name('detection.gates.api');
});

// Trace Investigation
Route::middleware(['auth', 'soc:trace.view'])->group(function () {
    Route::get('/traces', [TraceInvestigationController::class, 'index'])->name('traces.index');
    Route::get('/traces/{traceId}', [TraceInvestigationController::class, 'show'])->name('traces.show');
});

// Trace API (JSON)
Route::middleware(['auth', 'soc:trace.view', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::get('/traces', [TraceApiController::class, 'index'])->name('api.traces.index');
    Route::get('/traces/{traceId}', [TraceApiController::class, 'show'])->name('api.traces.show');
    Route::get('/traces/{traceId}/timeline', [TraceApiController::class, 'timeline'])->name('api.traces.timeline');
    Route::get('/traces/{traceId}/evidence', [TraceApiController::class, 'evidence'])->name('api.traces.evidence');
    Route::get('/traces/{traceId}/alerts', [TraceApiController::class, 'alerts'])->name('api.traces.alerts');
    Route::get('/traces/{traceId}/incidents', [TraceApiController::class, 'incidents'])->name('api.traces.incidents');
});

// Entity Graph — Investigation Pivoting
Route::middleware(['auth', 'soc:entity.view'])->group(function () {
    Route::get('/entity', [EntityController::class, 'index'])->name('entity.index');
    Route::get('/entity/{id}', [EntityController::class, 'show'])->name('entity.show');
    Route::get('/entity/{id}/timeline', [EntityController::class, 'timeline'])->name('entity.timeline');
    Route::get('/entity/{id}/graph', [EntityController::class, 'graph'])->name('entity.graph');
});

// Entity Risk — Investigation Prioritization (advisory only)
Route::middleware(['auth', 'soc:entity.view'])->group(function () {
    Route::get('/entity-risk', [EntityRiskController::class, 'dashboard'])->name('entity.risk-dashboard');
    Route::get('/entity-risk/{id}/breakdown', [EntityRiskController::class, 'breakdown'])->name('entity.risk-breakdown');
});

// Entity Graph API (JSON)
Route::middleware(['auth', 'soc:entity.view', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::get('/entities', [EntityApiController::class, 'index'])->name('api.entities.index');
    Route::get('/entities/{id}', [EntityApiController::class, 'show'])->name('api.entities.show');
    Route::get('/entities/{id}/timeline', [EntityApiController::class, 'timeline'])->name('api.entities.timeline');
    Route::get('/entities/{id}/relationships', [EntityApiController::class, 'relationships'])->name('api.entities.relationships');
    Route::get('/entities/{id}/alerts', [EntityApiController::class, 'alerts'])->name('api.entities.alerts');
    Route::get('/entities/{id}/incidents', [EntityApiController::class, 'incidents'])->name('api.entities.incidents');
    Route::get('/entities/{id}/risk', [EntityRiskApiController::class, 'entityRisk'])->name('api.entities.risk');
    Route::get('/entities/{id}/risk-history', [EntityRiskApiController::class, 'riskHistory'])->name('api.entities.risk-history');
});

// Entity Risk API
Route::middleware(['auth', 'soc:entity.view', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::get('/entity-risk', [EntityRiskApiController::class, 'index'])->name('api.entity-risk.index');
    Route::get('/entity-risk/top', [EntityRiskApiController::class, 'top'])->name('api.entity-risk.top');
});

// Investigation Workflow Orchestration
Route::middleware(['auth', 'soc:investigation.view'])->group(function () {
    Route::get('/investigations', [InvestigationController::class, 'index'])->name('investigation.index');
    Route::get('/investigations/queue', [InvestigationController::class, 'queue'])->name('investigation.queue');
    Route::get('/investigations/escalations', [InvestigationController::class, 'escalations'])->name('investigation.escalations');
    Route::get('/investigations/{id}', [InvestigationController::class, 'show'])->name('investigation.show');
});

Route::middleware(['auth', 'soc:investigation.create'])->group(function () {
    Route::post('/investigations', [InvestigationController::class, 'store'])->name('investigation.store');
});

Route::middleware(['auth', 'soc:investigation.update'])->group(function () {
    Route::post('/investigations/{id}/state', [InvestigationController::class, 'transition'])->name('investigation.transition');
});

Route::middleware(['auth', 'soc:investigation.assign'])->group(function () {
    Route::post('/investigations/{id}/assign', [InvestigationController::class, 'assign'])->name('investigation.assign');
});

Route::middleware(['auth', 'soc:investigation.note'])->group(function () {
    Route::post('/investigations/{id}/notes', [InvestigationController::class, 'storeNote'])->name('investigation.notes.store');
});

// Investigation API (JSON)
Route::middleware(['auth', 'soc:investigation.view', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::get('/investigations', [InvestigationApiController::class, 'index'])->name('api.investigations.index');
    Route::get('/investigations/{id}', [InvestigationApiController::class, 'show'])->name('api.investigations.show');
    Route::get('/investigations/{id}/timeline', [InvestigationApiController::class, 'timeline'])->name('api.investigations.timeline');
    Route::get('/investigations/{id}/notes', [InvestigationApiController::class, 'notes'])->name('api.investigations.notes');
});

Route::middleware(['auth', 'soc:investigation.note', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::post('/investigations/{id}/notes', [InvestigationApiController::class, 'storeNote'])->name('api.investigations.notes.store');
});

Route::middleware(['auth', 'soc:investigation.assign', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::post('/investigations/{id}/assign', [InvestigationApiController::class, 'assign'])->name('api.investigations.assign');
});

Route::middleware(['auth', 'soc:investigation.update', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::post('/investigations/{id}/state', [InvestigationApiController::class, 'transition'])->name('api.investigations.state');
});

// Advanced Threat Hunting & Investigation — advisory-only, no autonomous enforcement
Route::middleware(['auth', 'soc:investigation.view'])->group(function () {
    Route::get('/investigation/advanced', [AdvancedHuntingController::class, 'workspace'])->name('investigation.advanced.workspace');
    Route::get('/investigation/advanced/graph/{sessionId}', [AdvancedHuntingController::class, 'graphExplorer'])->name('investigation.advanced.graph-explorer');
    Route::get('/investigation/advanced/timeline/{investigationId}', [AdvancedHuntingController::class, 'attackTimeline'])->name('investigation.advanced.attack-timeline');
    Route::get('/investigation/advanced/pivot', [AdvancedHuntingController::class, 'multiHopPivot'])->name('investigation.advanced.multi-hop-pivot');
    Route::get('/investigation/advanced/retrospective', [AdvancedHuntingController::class, 'retrospectiveHunt'])->name('investigation.advanced.retrospective-hunt');
    Route::get('/investigation/advanced/evidence/{investigationId}', [AdvancedHuntingController::class, 'evidenceRelationships'])->name('investigation.advanced.evidence-relationships');
    Route::get('/investigation/advanced/attack-map', [AdvancedHuntingController::class, 'attackInvestigationMap'])->name('investigation.advanced.attack-investigation-map');
    Route::get('/investigation/advanced/cross-domain', [AdvancedHuntingController::class, 'crossDomainExplorer'])->name('investigation.advanced.cross-domain-explorer');
    Route::get('/investigation/advanced/sessions', [AdvancedHuntingController::class, 'sessionHistory'])->name('investigation.advanced.session-history');
});

// Compliance / Governance / Evidence Integrity — audit-visible, replay-safe, no autonomous remediation
Route::middleware(['auth', 'soc:audit.view'])->group(function () {
    Route::get('/governance', [ComplianceGovernanceController::class, 'evidenceIntegrityDashboard'])->name('governance.integrity-dashboard');
    Route::get('/governance/retention', [ComplianceGovernanceController::class, 'retentionGovernanceExplorer'])->name('governance.retention');
    Route::get('/governance/export', [ComplianceGovernanceController::class, 'auditExportWorkflow'])->name('governance.export-workflow');
    Route::get('/governance/tenant-isolation', [ComplianceGovernanceController::class, 'tenantIsolationValidator'])->name('governance.tenant-isolation');
    Route::get('/governance/pii-audit', [ComplianceGovernanceController::class, 'piiAccessAuditViewer'])->name('governance.pii-audit');
    Route::get('/governance/access-review', [ComplianceGovernanceController::class, 'governanceAccessReviewConsole'])->name('governance.access-review');
    Route::get('/governance/compliance', [ComplianceGovernanceController::class, 'complianceReportingDashboard'])->name('governance.compliance-report');
    Route::get('/governance/failures', [ComplianceGovernanceController::class, 'integrityFailureTimeline'])->name('governance.integrity-failures');
    Route::get('/governance/findings', [ComplianceGovernanceController::class, 'governanceFindingsExplorer'])->name('governance.findings');
});

// HA / Distributed Reliability — operational safeguards, no autonomous remediation
Route::middleware(['auth', 'soc:dashboard.view'])->group(function () {
    Route::get('/reliability', [DistributedReliabilityController::class, 'reliabilityDashboard'])->name('reliability.dashboard');
    Route::get('/reliability/workers', [DistributedReliabilityController::class, 'workerHealthExplorer'])->name('reliability.worker-health');
    Route::get('/reliability/lag', [DistributedReliabilityController::class, 'consumerLagMonitor'])->name('reliability.lag-monitor');
    Route::get('/reliability/throttle', [DistributedReliabilityController::class, 'replayThrottleConsole'])->name('reliability.throttle-console');
    Route::get('/reliability/idempotency', [DistributedReliabilityController::class, 'idempotencyExplorer'])->name('reliability.idempotency');
    Route::get('/reliability/duplicates', [DistributedReliabilityController::class, 'duplicateEventReports'])->name('reliability.duplicate-reports');
    Route::get('/reliability/storage', [DistributedReliabilityController::class, 'storagePressureDashboard'])->name('reliability.storage-pressure');
    Route::get('/reliability/degraded', [DistributedReliabilityController::class, 'degradedModeTimeline'])->name('reliability.degraded-mode');
    Route::get('/reliability/recovery', [DistributedReliabilityController::class, 'recoveryValidationViewer'])->name('reliability.recovery-validation');
});

// SOAR Governance & Response Orchestration — simulation-first, approval-gated, advisory-only
Route::middleware(['auth', 'soc:response.view'])->group(function () {
    Route::get('/soar', [SoarOrchestrationController::class, 'governanceDashboard'])->name('soar.governance-dashboard');
    Route::get('/soar/playbooks', [SoarOrchestrationController::class, 'playbookRegistry'])->name('soar.playbook-registry');
    Route::get('/soar/playbooks/{playbookId}/versions', [SoarOrchestrationController::class, 'playbookVersionHistory'])->name('soar.playbook-version-history');
    Route::get('/soar/simulation', [SoarOrchestrationController::class, 'simulationRunner'])->name('soar.simulation-runner');
    Route::get('/soar/plans/{planId}', [SoarOrchestrationController::class, 'executionPlanViewer'])->name('soar.execution-plan-viewer');
    Route::get('/soar/approvals', [SoarOrchestrationController::class, 'approvalConsole'])->name('soar.approval-console');
    Route::get('/soar/rollback', [SoarOrchestrationController::class, 'rollbackViewer'])->name('soar.rollback-viewer');
    Route::get('/soar/audit', [SoarOrchestrationController::class, 'auditTimeline'])->name('soar.audit-timeline');
    Route::get('/soar/artifacts', [SoarOrchestrationController::class, 'simulationArtifacts'])->name('soar.simulation-artifacts');
});

// Response Planning — advisory only, no automated execution
Route::middleware(['auth', 'soc:response.view'])->group(function () {
    Route::get('/response-plans', [ResponsePlanController::class, 'index'])->name('response.index');
    Route::get('/response-plans/recommendations', [ResponsePlanController::class, 'recommendations'])->name('response.recommendations');
    Route::get('/response-plans/{id}', [ResponsePlanController::class, 'show'])->name('response.show');
});

Route::middleware(['auth', 'soc:response.create'])->group(function () {
    Route::post('/response-plans', [ResponsePlanController::class, 'store'])->name('response.store');
    Route::post('/response-plans/{id}/submit', [ResponsePlanController::class, 'submit'])->name('response.submit');
    Route::post('/response-plans/{id}/complete', [ResponsePlanController::class, 'complete'])->name('response.complete');
    Route::post('/response-plans/{id}/cancel', [ResponsePlanController::class, 'cancel'])->name('response.cancel');
});

Route::middleware(['auth', 'soc:response.approve'])->group(function () {
    Route::post('/response-plans/{id}/approve', [ResponsePlanController::class, 'approve'])->name('response.approve');
    Route::post('/response-plans/{id}/reject', [ResponsePlanController::class, 'reject'])->name('response.reject');
});

Route::middleware(['auth', 'soc:response.note'])->group(function () {
    Route::post('/response-plans/{id}/notes', [ResponsePlanController::class, 'storeNote'])->name('response.notes.store');
});

// Response Planning API (JSON)
Route::middleware(['auth', 'soc:response.view', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::get('/response-plans', [ResponsePlanApiController::class, 'index'])->name('api.response-plans.index');
    Route::get('/response-plans/recommendations', [ResponsePlanApiController::class, 'recommendations'])->name('api.response-plans.recommendations');
    Route::get('/response-plans/{id}', [ResponsePlanApiController::class, 'show'])->name('api.response-plans.show');
    Route::get('/response-plans/{id}/timeline', [ResponsePlanApiController::class, 'timeline'])->name('api.response-plans.timeline');
});

Route::middleware(['auth', 'soc:response.approve', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::post('/response-plans/{id}/approve', [ResponsePlanApiController::class, 'approve'])->name('api.response-plans.approve');
    Route::post('/response-plans/{id}/reject', [ResponsePlanApiController::class, 'reject'])->name('api.response-plans.reject');
});

Route::middleware(['auth', 'soc:response.note', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::post('/response-plans/{id}/notes', [ResponsePlanApiController::class, 'storeNote'])->name('api.response-plans.notes');
});

// Export Center — report generation (analyst+) and history (all)
Route::middleware(['auth', 'soc:report.export'])->group(function () {
    Route::get('/exports', [ExportController::class, 'index'])->name('export.index');
    Route::post('/exports/investigation/{id}', [ExportController::class, 'downloadInvestigation'])->name('export.investigation');
    Route::post('/exports/response-plan/{id}', [ExportController::class, 'downloadResponsePlan'])->name('export.response-plan');
    Route::post('/exports/entity-risk/{id}', [ExportController::class, 'downloadEntityRisk'])->name('export.entity-risk');
    Route::post('/exports/trace/{traceId}', [ExportController::class, 'downloadTrace'])->name('export.trace');
});

Route::middleware(['auth', 'soc:report.view'])->group(function () {
    Route::get('/exports/history', [ExportController::class, 'history'])->name('export.history');
});

// Export API
Route::middleware(['auth', 'soc:report.view', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::get('/exports', [ExportApiController::class, 'history'])->name('api.exports.history');
});

Route::middleware(['auth', 'soc:report.export', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::post('/exports/investigation/{id}', [ExportApiController::class, 'exportInvestigation'])->name('api.exports.investigation');
    Route::post('/exports/response-plan/{id}', [ExportApiController::class, 'exportResponsePlan'])->name('api.exports.response-plan');
    Route::post('/exports/entity-risk/{id}', [ExportApiController::class, 'exportEntityRisk'])->name('api.exports.entity-risk');
    Route::post('/exports/trace/{traceId}', [ExportApiController::class, 'exportTrace'])->name('api.exports.trace');
});

// Threat Hunting & Investigation Query Engine — advisory-only, non-destructive
Route::middleware(['auth', 'soc:investigation.view'])->group(function () {
    Route::get('/threat-hunts',                       [ThreatHuntController::class, 'dashboard'])->name('threat-hunt.dashboard');
    Route::get('/threat-hunts/new',                   [ThreatHuntController::class, 'queryBuilder'])->name('threat-hunt.query-builder');
    Route::get('/threat-hunts/history',               [ThreatHuntController::class, 'historyReplay'])->name('threat-hunt.history');
    Route::get('/threat-hunts/pivot',                 [ThreatHuntController::class, 'pivotExplorer'])->name('threat-hunt.pivot-explorer');
    Route::get('/threat-hunts/beacon',                [ThreatHuntController::class, 'beaconInvestigation'])->name('threat-hunt.beacon-investigation');
    Route::get('/threat-hunts/persistence',           [ThreatHuntController::class, 'persistenceInvestigation'])->name('threat-hunt.persistence-investigation');
    Route::get('/threat-hunts/chains',                [ThreatHuntController::class, 'chainExplorer'])->name('threat-hunt.chain-explorer');
    Route::get('/threat-hunts/{huntId}',              [ThreatHuntController::class, 'show'])->name('threat-hunt.show');
});

Route::middleware(['auth', 'soc:investigation.create'])->group(function () {
    Route::post('/threat-hunts',                      [ThreatHuntController::class, 'executeQuery'])->name('threat-hunt.execute');
    Route::post('/threat-hunts/{huntId}/replay',      [ThreatHuntController::class, 'replay'])->name('threat-hunt.replay');
});

// Threat Hunt API
Route::middleware(['auth', 'soc:investigation.view', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::get('/threat-hunts',                              [ThreatHuntApiController::class, 'listHunts'])->name('api.threat-hunts.list');
    Route::get('/threat-hunts/{huntId}',                     [ThreatHuntApiController::class, 'getHunt'])->name('api.threat-hunts.show');
    Route::get('/threat-hunts/{huntId}/results',             [ThreatHuntApiController::class, 'getResults'])->name('api.threat-hunts.results');
    Route::get('/threat-hunts/pivot/{entityType}',           [ThreatHuntApiController::class, 'pivot'])->name('api.threat-hunts.pivot');
});

Route::middleware(['auth', 'soc:investigation.create', 'throttle:soc-api'])->prefix('api')->group(function () {
    Route::post('/threat-hunts/query',                       [ThreatHuntApiController::class, 'executeQuery'])->name('api.threat-hunts.query');
});

// Security Hardening Dashboard (admin only)
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/security/hardening', [SecurityHardeningController::class, 'index'])->name('security.hardening');
});

// Resilience Validation Dashboard (admin only)
Route::middleware(['auth', 'admin'])->prefix('resilience')->group(function () {
    Route::get('/',            [ResilienceController::class, 'index'])->name('resilience.index');
    Route::get('/history',     [ResilienceController::class, 'history'])->name('resilience.history');
    Route::get('/{runId}',     [ResilienceController::class, 'show'])->name('resilience.show');
    Route::post('/run',        [ResilienceController::class, 'run'])->name('resilience.run');
});

// Cross-Domain Correlation — advisory-only, shadow/retrospective investigation
Route::middleware(['auth', 'soc:investigation.view'])->group(function () {
    Route::get('/cross-domain',                    [CrossDomainController::class, 'dashboard'])->name('cross-domain.dashboard');
    Route::get('/cross-domain/attack-timeline',    [CrossDomainController::class, 'attackTimeline'])->name('cross-domain.attack-timeline');
    Route::get('/cross-domain/identity-endpoint',  [CrossDomainController::class, 'identityEndpointPivot'])->name('cross-domain.identity-endpoint');
    Route::get('/cross-domain/investigation-graph',[CrossDomainController::class, 'investigationGraph'])->name('cross-domain.investigation-graph');
    Route::get('/cross-domain/shared-destination', [CrossDomainController::class, 'sharedDestination'])->name('cross-domain.shared-destination');
    Route::get('/cross-domain/trace-explorer',     [CrossDomainController::class, 'traceExplorer'])->name('cross-domain.trace-explorer');
    Route::get('/cross-domain/{correlationId}',    [CrossDomainController::class, 'show'])->name('cross-domain.show');
    Route::post('/cross-domain/run',               [CrossDomainController::class, 'runCorrelation'])->name('cross-domain.run')->middleware('soc:investigation.create');
});

Route::middleware(['auth', 'soc:investigation.view'])->prefix('api')->group(function () {
    Route::get('/cross-domain/correlations',             [CrossDomainApiController::class, 'listCorrelations'])->name('api.cross-domain.list');
    Route::get('/cross-domain/correlations/{id}',        [CrossDomainApiController::class, 'getCorrelation'])->name('api.cross-domain.show');
    Route::get('/cross-domain/attack-stages',            [CrossDomainApiController::class, 'getAttackStages'])->name('api.cross-domain.stages');
    Route::get('/cross-domain/pivot/identity-host',      [CrossDomainApiController::class, 'pivotIdentityHost'])->name('api.cross-domain.pivot.identity-host');
    Route::get('/cross-domain/pivot/multi-host',         [CrossDomainApiController::class, 'pivotMultiHost'])->name('api.cross-domain.pivot.multi-host');
    Route::get('/cross-domain/pivot/attack-stage',       [CrossDomainApiController::class, 'pivotAttackStage'])->name('api.cross-domain.pivot.stage');
    Route::get('/cross-domain/pivot/trace',              [CrossDomainApiController::class, 'pivotTrace'])->name('api.cross-domain.pivot.trace');
});

// Active Response Execution — Phase 2: controlled, approval-gated, manually-driven
Route::middleware(['auth', 'soc:response.view'])->group(function () {
    Route::get('/active-response',                           [ActiveResponseController::class, 'dashboard'])->name('active-response.dashboard');
    Route::get('/active-response/approval-queue',            [ActiveResponseController::class, 'approvalQueue'])->name('active-response.approval-queue');
    Route::get('/active-response/rollback-center',           [ActiveResponseController::class, 'rollbackCenter'])->name('active-response.rollback-center');
    Route::get('/active-response/audit',                     [ActiveResponseController::class, 'auditExplorer'])->name('active-response.audit-explorer');
    Route::get('/active-response/{id}',                      [ActiveResponseController::class, 'show'])->name('active-response.show');
    Route::get('/active-response/{id}/simulation',           [ActiveResponseController::class, 'simulationPreview'])->name('active-response.simulation-preview');
    Route::get('/active-response/{id}/blast-radius',         [ActiveResponseController::class, 'blastRadiusView'])->name('active-response.blast-radius');
    Route::get('/active-response/{id}/timeline',             [ActiveResponseController::class, 'executionTimeline'])->name('active-response.execution-timeline');
});

Route::middleware(['auth', 'soc:response.create'])->group(function () {
    Route::post('/active-response',                          [ActiveResponseController::class, 'store'])->name('active-response.store');
    Route::post('/active-response/{id}/submit',              [ActiveResponseController::class, 'submit'])->name('active-response.submit');
    Route::post('/active-response/{id}/simulate',            [ActiveResponseController::class, 'simulate'])->name('active-response.simulate');
    Route::post('/active-response/{id}/request-execution',   [ActiveResponseController::class, 'requestExecution'])->name('active-response.request-execution');
    Route::post('/active-response/{id}/execute',             [ActiveResponseController::class, 'execute'])->name('active-response.execute');
    Route::post('/active-response/{id}/initiate-rollback',   [ActiveResponseController::class, 'initiateRollback'])->name('active-response.initiate-rollback');
    Route::post('/active-response/{id}/complete-rollback',   [ActiveResponseController::class, 'completeRollback'])->name('active-response.complete-rollback');
    Route::post('/active-response/{id}/cancel',              [ActiveResponseController::class, 'cancel'])->name('active-response.cancel');
});

Route::middleware(['auth', 'soc:response.approve'])->group(function () {
    Route::post('/active-response/{id}/approve',             [ActiveResponseController::class, 'approve'])->name('active-response.approve');
    Route::post('/active-response/{id}/reject',              [ActiveResponseController::class, 'reject'])->name('active-response.reject');
});

Route::middleware(['auth', 'soc:response.view'])->prefix('api')->group(function () {
    Route::get('/active-response/executions',            [ActiveResponseApiController::class, 'listExecutions'])->name('api.active-response.list');
    Route::get('/active-response/executions/{id}',       [ActiveResponseApiController::class, 'getExecution'])->name('api.active-response.show');
    Route::get('/active-response/pending-approvals',     [ActiveResponseApiController::class, 'getPendingApprovals'])->name('api.active-response.pending');
    Route::get('/active-response/executions/{id}/simulation', [ActiveResponseApiController::class, 'getSimulation'])->name('api.active-response.simulation');
    Route::get('/active-response/allowed-actions',       [ActiveResponseApiController::class, 'getAllowedActions'])->name('api.active-response.actions');
});

// Endpoint Streaming Telemetry — Phase 1
// Near-real-time advisory telemetry. Not kernel-level EDR.
Route::middleware(['auth', 'soc:trace.view'])->group(function () {
    Route::get('/endpoint/stream/feed',            [EndpointStreamController::class, 'activityFeed'])->name('endpoint.stream.feed');
    Route::get('/endpoint/stream/monitor',         [EndpointStreamController::class, 'monitor'])->name('endpoint.stream.monitor');
    Route::get('/endpoint/stream/health',          [EndpointStreamController::class, 'health'])->name('endpoint.stream.health');
    Route::get('/endpoint/stream/burst',           [EndpointStreamController::class, 'burstActivity'])->name('endpoint.stream.burst');
    Route::get('/endpoint/stream/replay',          [EndpointStreamController::class, 'replayInspector'])->name('endpoint.stream.replay');
    Route::get('/endpoint/stream/{agentId}/timeline', [EndpointStreamController::class, 'eventTimeline'])->name('endpoint.stream.timeline');
});

Route::middleware(['auth', 'soc:trace.view'])->prefix('api')->group(function () {
    Route::get('/endpoint/stream/events',          [EndpointStreamApiController::class, 'getStreamEvents'])->name('api.endpoint.stream.events');
    Route::get('/endpoint/stream/health',          [EndpointStreamApiController::class, 'getStreamHealth'])->name('api.endpoint.stream.health');
    Route::get('/endpoint/stream/checkpoints',     [EndpointStreamApiController::class, 'getStreamCheckpoints'])->name('api.endpoint.stream.checkpoints');
    Route::get('/endpoint/stream/{agentId}/replay', [EndpointStreamApiController::class, 'getReplayWindow'])->name('api.endpoint.stream.replay');
    Route::get('/endpoint/stream/{agentId}/analytics', [EndpointStreamApiController::class, 'getRapidAnalytics'])->name('api.endpoint.stream.analytics');
});

// Agent stream ingestion — authenticated by enrollment token header
Route::prefix('api/agents')->group(function () {
    Route::post('/{agentId}/stream-batch', [EndpointStreamApiController::class, 'ingestBatch'])->name('api.agents.stream.batch');
});

// Production Operations Hardening — Phase 1
// Operational hardening and recovery tooling only. No autonomous infrastructure mutation.
Route::middleware(['auth', 'admin'])->prefix('operations')->group(function () {
    Route::get('/',              [OperationsController::class, 'healthDashboard'])->name('operations.health');
    Route::get('/queue-health',  [OperationsController::class, 'queueHealth'])->name('operations.queue');
    Route::get('/retention',     [OperationsController::class, 'retentionCenter'])->name('operations.retention');
    Route::get('/backup',        [OperationsController::class, 'backupRecovery'])->name('operations.backup');
    Route::get('/dlq',           [OperationsController::class, 'dlqReplayCenter'])->name('operations.dlq');
    Route::get('/graph',         [OperationsController::class, 'deploymentGraph'])->name('operations.graph');
    Route::get('/warnings',      [OperationsController::class, 'warningsTimeline'])->name('operations.warnings');

    Route::post('/retention/{policyId}/preview',  [OperationsController::class, 'previewRetention'])->name('operations.retention.preview');
    Route::post('/retention/{policyId}/dry-run',  [OperationsController::class, 'runDryRun'])->name('operations.retention.dry-run');

    Route::post('/dlq/jobs',                      [OperationsController::class, 'createDlqJob'])->name('operations.dlq.create');
    Route::post('/dlq/jobs/{jobId}/simulate',     [OperationsController::class, 'simulateDlqJob'])->name('operations.dlq.simulate');
    Route::post('/dlq/jobs/{jobId}/cancel',       [OperationsController::class, 'cancelDlqJob'])->name('operations.dlq.cancel');
});

Route::middleware(['auth', 'admin'])->prefix('api/operations')->group(function () {
    Route::get('/health',       [OperationsApiController::class, 'getHealthSummary'])->name('api.operations.health');
    Route::get('/queue-lag',    [OperationsApiController::class, 'getQueueLag'])->name('api.operations.queue-lag');
    Route::get('/graph',        [OperationsApiController::class, 'getDependencyGraph'])->name('api.operations.graph');
    Route::get('/warnings',     [OperationsApiController::class, 'getWarnings'])->name('api.operations.warnings');
    Route::get('/backup',       [OperationsApiController::class, 'getBackupSummary'])->name('api.operations.backup');
    Route::get('/dlq/jobs',     [OperationsApiController::class, 'getDlqJobs'])->name('api.operations.dlq-jobs');
    Route::get('/dlq/jobs/{jobId}/preview', [OperationsApiController::class, 'getDlqPreview'])->name('api.operations.dlq-preview');
    Route::get('/retention/{policyId}/preview', [OperationsApiController::class, 'previewRetention'])->name('api.operations.retention-preview');
});

// DNS / Proxy / Firewall Network Analytics — Phase 1
// Network analytics are shadow-only and advisory. No blocking or containment is executed.
Route::middleware(['auth', 'soc:trace.view'])->prefix('network')->group(function () {
    Route::get('/',             [NetworkAnalyticsController::class, 'dashboard'])->name('network.dashboard');
    Route::get('/dns',          [NetworkAnalyticsController::class, 'dnsInvestigation'])->name('network.dns');
    Route::get('/proxy',        [NetworkAnalyticsController::class, 'proxyInvestigation'])->name('network.proxy');
    Route::get('/firewall',     [NetworkAnalyticsController::class, 'firewallInvestigation'])->name('network.firewall');
    Route::get('/destination',  [NetworkAnalyticsController::class, 'destinationProfile'])->name('network.destination');
    Route::get('/findings',     [NetworkAnalyticsController::class, 'behavioralFindings'])->name('network.findings');
    Route::get('/hunt',         [NetworkAnalyticsController::class, 'threatHuntingPivots'])->name('network.hunt');
});

Route::middleware(['auth', 'soc:trace.view'])->prefix('api/network')->group(function () {
    Route::get('/dashboard',    [NetworkAnalyticsApiController::class, 'getDashboard'])->name('api.network.dashboard');
    Route::get('/dns',          [NetworkAnalyticsApiController::class, 'getDnsEvents'])->name('api.network.dns');
    Route::get('/proxy',        [NetworkAnalyticsApiController::class, 'getProxyEvents'])->name('api.network.proxy');
    Route::get('/firewall',     [NetworkAnalyticsApiController::class, 'getFirewallEvents'])->name('api.network.firewall');
    Route::get('/findings',     [NetworkAnalyticsApiController::class, 'getFindings'])->name('api.network.findings');
    Route::get('/pivot',        [NetworkAnalyticsApiController::class, 'pivot'])->name('api.network.pivot');

    Route::post('/dns/ingest',       [NetworkAnalyticsApiController::class, 'ingestDns'])->name('api.network.dns.ingest');
    Route::post('/proxy/ingest',     [NetworkAnalyticsApiController::class, 'ingestProxy'])->name('api.network.proxy.ingest');
    Route::post('/firewall/ingest',  [NetworkAnalyticsApiController::class, 'ingestFirewall'])->name('api.network.firewall.ingest');
});

// SOC Collaboration & Analyst Workflow Layer — Phase 1
// Analyst-driven collaborative workflows only. No autonomous SOC operations.
Route::middleware(['auth', 'soc:investigation.view'])->prefix('soc/workflow')->group(function () {
    Route::get('/',              [SocWorkflowController::class, 'operationsDashboard'])->name('soc.workflow.dashboard');
    Route::get('/queue',         [SocWorkflowController::class, 'analystQueue'])->name('soc.workflow.queue');
    Route::get('/escalation',    [SocWorkflowController::class, 'escalationCenter'])->name('soc.workflow.escalation');
    Route::get('/watchlist',     [SocWorkflowController::class, 'watchlistCenter'])->name('soc.workflow.watchlist');
    Route::get('/sla',           [SocWorkflowController::class, 'slaMonitoring'])->name('soc.workflow.sla');
    Route::get('/handoff',       [SocWorkflowController::class, 'shiftHandoff'])->name('soc.workflow.handoff');
    Route::get('/timeline',      [SocWorkflowController::class, 'collaborationTimeline'])->name('soc.workflow.timeline');
    Route::get('/workload',      [SocWorkflowController::class, 'analystWorkload'])->name('soc.workflow.workload');
    Route::get('/shared',        [SocWorkflowController::class, 'sharedInvestigations'])->name('soc.workflow.shared');

    Route::post('/handoff',                    [SocWorkflowController::class, 'createHandoff'])->name('soc.workflow.handoff.create');
    Route::post('/watchlist',                  [SocWorkflowController::class, 'createWatchlist'])->name('soc.workflow.watchlist.create');
    Route::post('/escalation',                 [SocWorkflowController::class, 'createEscalation'])->name('soc.workflow.escalation.create');
});

Route::middleware(['auth', 'soc:investigation.view'])->prefix('api/soc/workflow')->group(function () {
    Route::get('/summary',       [SocWorkflowApiController::class, 'getOperationsSummary'])->name('api.soc.workflow.summary');
    Route::get('/escalations',   [SocWorkflowApiController::class, 'getEscalationQueue'])->name('api.soc.workflow.escalations');
    Route::get('/sla-breaches',  [SocWorkflowApiController::class, 'getSlaBreaches'])->name('api.soc.workflow.breaches');
    Route::get('/workload',      [SocWorkflowApiController::class, 'getAnalystWorkload'])->name('api.soc.workflow.workload');
    Route::get('/watchlists',    [SocWorkflowApiController::class, 'getWatchlists'])->name('api.soc.workflow.watchlists');
    Route::get('/timeline',      [SocWorkflowApiController::class, 'getCollaborationTimeline'])->name('api.soc.workflow.timeline');
    Route::post('/collaborator', [SocWorkflowApiController::class, 'addCollaborator'])->name('api.soc.workflow.collaborator');
    Route::post('/escalations/{escalationId}/acknowledge', [SocWorkflowApiController::class, 'acknowledgeEscalation'])->name('api.soc.workflow.escalation.ack');
});

// Enterprise Integrations — Phase 1
// External identity, SaaS, ticketing, and notification integrations. Advisory-only inbound.
// No autonomous account suspension, no bidirectional destructive sync.
Route::middleware(['auth', 'soc:investigation.view'])->prefix('integrations')->group(function () {
    Route::get('/',              [EnterpriseIntegrationController::class, 'dashboard'])->name('integrations.dashboard');
    Route::get('/registry',      [EnterpriseIntegrationController::class, 'integrationRegistry'])->name('integrations.registry');
    Route::get('/idp-feed',      [EnterpriseIntegrationController::class, 'identityProviderFeed'])->name('integrations.idp-feed');
    Route::get('/saas-audit',    [EnterpriseIntegrationController::class, 'saasAuditFeed'])->name('integrations.saas-audit');
    Route::get('/notifications', [EnterpriseIntegrationController::class, 'notificationCenter'])->name('integrations.notifications');
    Route::get('/case-links',    [EnterpriseIntegrationController::class, 'caseLinks'])->name('integrations.case-links');
    Route::get('/sync-history',  [EnterpriseIntegrationController::class, 'syncHistory'])->name('integrations.sync-history');

    Route::post('/',                                   [EnterpriseIntegrationController::class, 'registerIntegration'])->name('integrations.register');
    Route::post('/{integration}/activate',             [EnterpriseIntegrationController::class, 'activateIntegration'])->name('integrations.activate');
    Route::post('/{integration}/disable',              [EnterpriseIntegrationController::class, 'disableIntegration'])->name('integrations.disable');
    Route::post('/{integration}/link-case',            [EnterpriseIntegrationController::class, 'linkCase'])->name('integrations.link-case');
    Route::post('/{integration}/notifications',        [EnterpriseIntegrationController::class, 'sendNotification'])->name('integrations.notify');
});

Route::middleware(['auth', 'soc:investigation.view'])->prefix('api/integrations')->group(function () {
    Route::get('/',                                    [EnterpriseIntegrationApiController::class, 'getIntegrations'])->name('api.integrations.list');
    Route::get('/idp-events',                          [EnterpriseIntegrationApiController::class, 'getIdpEvents'])->name('api.integrations.idp-events');
    Route::get('/saas-events',                         [EnterpriseIntegrationApiController::class, 'getSaasEvents'])->name('api.integrations.saas-events');
    Route::get('/high-risk',                           [EnterpriseIntegrationApiController::class, 'getHighRiskEvents'])->name('api.integrations.high-risk');
    Route::get('/notifications',                       [EnterpriseIntegrationApiController::class, 'getNotifications'])->name('api.integrations.notifications');
    Route::get('/{integration}/sync-history',          [EnterpriseIntegrationApiController::class, 'getSyncHistory'])->name('api.integrations.sync-history');

    Route::post('/{integration}/ingest-idp',           [EnterpriseIntegrationApiController::class, 'ingestIdpEvents'])->name('api.integrations.ingest-idp');
    Route::post('/{integration}/ingest-saas',          [EnterpriseIntegrationApiController::class, 'ingestSaasEvents'])->name('api.integrations.ingest-saas');
});

// Endpoint Fleet Hardening Phase 1 — Production fleet management
// Advisory-only. No autonomous containment, no isolation, no shell execution.
Route::middleware(['auth', 'soc:agents.manage'])->prefix('endpoint-fleet')->group(function () {
    Route::get('/',             [EndpointFleetController::class, 'dashboard'])->name('endpoint-fleet.dashboard');
    Route::get('/health',       [EndpointFleetController::class, 'agentHealth'])->name('endpoint-fleet.health');
    Route::get('/policies',     [EndpointFleetController::class, 'policyAssignment'])->name('endpoint-fleet.policies');
    Route::get('/enrollment',   [EndpointFleetController::class, 'enrollmentAudit'])->name('endpoint-fleet.enrollment');
    Route::get('/lag',          [EndpointFleetController::class, 'telemetryLag'])->name('endpoint-fleet.lag');
    Route::get('/tamper',       [EndpointFleetController::class, 'tamperVisibility'])->name('endpoint-fleet.tamper');
    Route::get('/spool',        [EndpointFleetController::class, 'spoolHealth'])->name('endpoint-fleet.spool');
    Route::get('/drift',        [EndpointFleetController::class, 'policyDrift'])->name('endpoint-fleet.drift');
});

Route::middleware(['auth', 'soc:agents.manage', 'throttle:soc-api'])->prefix('api/endpoint-fleet')->group(function () {
    Route::get('/stats',        [EndpointFleetApiController::class, 'getDashboardStats'])->name('api.endpoint-fleet.stats');
    Route::get('/stale',        [EndpointFleetApiController::class, 'getStaleAgents'])->name('api.endpoint-fleet.stale');
    Route::get('/lag',          [EndpointFleetApiController::class, 'getTelemetryLag'])->name('api.endpoint-fleet.lag');
    Route::get('/tamper',       [EndpointFleetApiController::class, 'getTamperSummary'])->name('api.endpoint-fleet.tamper');
    Route::post('/tamper/detect', [EndpointFleetApiController::class, 'detectTamper'])->name('api.endpoint-fleet.tamper.detect');
    Route::get('/spool',        [EndpointFleetApiController::class, 'getSpoolHealth'])->name('api.endpoint-fleet.spool');
    Route::get('/drift',        [EndpointFleetApiController::class, 'getPolicyDrift'])->name('api.endpoint-fleet.drift');
});

// UEBA Phase 1 — Behavioral Baseline Analytics
// Advisory-only. No automated enforcement, no account suspension, no host isolation.
Route::middleware(['auth', 'soc:entity.view'])->prefix('ueba')->group(function () {
    Route::get('/',              [UEBAController::class, 'dashboard'])->name('ueba.dashboard');
    Route::get('/baseline',      [UEBAController::class, 'baselineProfile'])->name('ueba.baseline-profile');
    Route::get('/anomalies',     [UEBAController::class, 'anomalyExplorer'])->name('ueba.anomaly-explorer');
    Route::get('/peer-groups',   [UEBAController::class, 'peerGroupComparison'])->name('ueba.peer-groups');
    Route::get('/history',       [UEBAController::class, 'entityBaselineHistory'])->name('ueba.entity-history');
    Route::get('/drift',         [UEBAController::class, 'baselineDriftMonitor'])->name('ueba.drift-monitor');
    Route::get('/risk',          [UEBAController::class, 'riskContribution'])->name('ueba.risk-contribution');
});

Route::middleware(['auth', 'soc:entity.view', 'throttle:soc-api'])->prefix('api/ueba')->group(function () {
    Route::get('/profile',              [UEBAApiController::class, 'baselineProfile'])->name('api.ueba.profile');
    Route::get('/anomaly-scores',       [UEBAApiController::class, 'anomalyScores'])->name('api.ueba.scores');
    Route::get('/peer-group/{key}',     [UEBAApiController::class, 'peerGroupProfile'])->name('api.ueba.peer-group');
    Route::get('/top-anomalous',        [UEBAApiController::class, 'topAnomalous'])->name('api.ueba.top');
    Route::get('/drift',                [UEBAApiController::class, 'driftSummary'])->name('api.ueba.drift');
    Route::get('/volume-trend',         [UEBAApiController::class, 'anomalyVolumeTrend'])->name('api.ueba.trend');
    Route::post('/detect',              [UEBAApiController::class, 'detectAnomalies'])->name('api.ueba.detect');
    Route::post('/compute-baseline',    [UEBAApiController::class, 'computeBaseline'])->name('api.ueba.compute');
});

// Internal Service Auth API — protected by X-Internal-Service-Token header
// Used for service-to-service authenticated calls and middleware testing.
Route::middleware([InternalServiceAuthMiddleware::class])->prefix('api/internal')->group(function () {
    Route::get('/status', function () {
        return response()->json([
            'ok'      => true,
            'service' => 'laravel-soc',
            'ts'      => now()->toIso8601String(),
        ]);
    })->name('api.internal.status');
});

// Low-level Endpoint Telemetry — Phase 1
// Advisory-only. No process termination, no kernel enforcement, no isolation.
Route::middleware(['auth', 'soc:agents.manage'])->prefix('endpoint-telemetry')->group(function () {
    Route::get('/',                     [EndpointTelemetryController::class, 'dashboard'])->name('endpoint-telemetry.dashboard');
    Route::get('/process-explorer',     [EndpointTelemetryController::class, 'processExplorer'])->name('endpoint-telemetry.process-explorer');
    Route::get('/process-tree',         [EndpointTelemetryController::class, 'processTree'])->name('endpoint-telemetry.process-tree');
    Route::get('/network-connections',  [EndpointTelemetryController::class, 'networkConnections'])->name('endpoint-telemetry.network-connections');
    Route::get('/script-execution',     [EndpointTelemetryController::class, 'scriptExecution'])->name('endpoint-telemetry.script-execution');
    Route::get('/privilege-escalation', [EndpointTelemetryController::class, 'privilegeEscalation'])->name('endpoint-telemetry.privilege-escalation');
    Route::get('/persistence',          [EndpointTelemetryController::class, 'persistenceIndicators'])->name('endpoint-telemetry.persistence');
    Route::get('/container-activity',   [EndpointTelemetryController::class, 'containerActivity'])->name('endpoint-telemetry.container-activity');
});


require __DIR__.'/auth.php';
