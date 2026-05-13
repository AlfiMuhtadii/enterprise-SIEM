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

require __DIR__.'/auth.php';
