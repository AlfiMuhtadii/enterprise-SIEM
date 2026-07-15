<?php

use App\Http\Controllers\AgentIngestionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// API-VERSIONING: registered once under /v1 (the new canonical prefix) and
// again unprefixed (kept exactly as before, for the endpoint agents and
// any other client already deployed against the unprefixed paths) --
// both resolve to the identical controller/closure, so there is no
// second copy of the logic to drift out of sync. See
// docs/api/DEPRECATION_POLICY.md for the sunset plan for the unprefixed
// alias.
$apiRoutes = function () {
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });

    Route::prefix('agents')->middleware('throttle:api')->group(function () {
        Route::post('/register', [AgentIngestionController::class, 'register']);
        Route::post('/config', [AgentIngestionController::class, 'config']);
        Route::post('/heartbeat', [AgentIngestionController::class, 'heartbeat']);
        Route::post('/telemetry', [AgentIngestionController::class, 'telemetry']);
        Route::post('/commands/result', [AgentIngestionController::class, 'commandResult']);
    });
};

Route::prefix('v1')->group($apiRoutes);
$apiRoutes();
