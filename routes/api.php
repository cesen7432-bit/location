<?php

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectorController;
use App\Http\Controllers\CollectorUserController;
use App\Http\Controllers\GarbageController;
use App\Http\Controllers\NeighborhoodController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\RouteGeometryController;
use App\Http\Controllers\RouteRunController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\UserLocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes — no authentication required
|--------------------------------------------------------------------------
*/
Route::prefix('public')->group(function () {
    // Schedules: all active routes with their weekly timetable
    // Filters: ?day=monday  ?zone=sur
    Route::get('schedules', [PublicController::class, 'schedules']);

    // "¿Cuándo pasan por mi barrio?"
    // Returns a 7-day breakdown of which routes + times cover this neighborhood
    Route::get('neighborhoods/{neighborhood}/schedule', [PublicController::class, 'neighborhoodSchedule']);

    // Live collector positions (poll fallback — use WebSocket for real-time)
    Route::get('collectors/live', [PublicController::class, 'liveCollectors']);

    // Today's simplified GPS path for one collector on one route
    // Load this, then subscribe to WebSocket channel collectors.live to append pings
    Route::get('collectors/{collectorId}/path', [PublicController::class, 'collectorPath']);

    // Planned GPS polyline for a route (neighborhood stops in order)
    Route::get('routes/{route}/points', [PublicController::class, 'routePoints']);

    Route::get('routes/{route}/geometry', [RouteGeometryController::class, 'show']);
    Route::get('push/public-key', [NotificationController::class, 'publicKey']);
});

/*
|--------------------------------------------------------------------------
| Auth routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [AuthController::class, 'updateMe']);
        Route::delete('me', [AuthController::class, 'deleteMe']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| Collector routes — role: collector (or admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:collector,admin'])->prefix('collector')->group(function () {
    Route::get('my-routes', [CollectorController::class, 'myRoutes']);

    Route::get('runs', [RouteRunController::class, 'mine']);
    Route::get('runs/current', [RouteRunController::class, 'current']);
    Route::post('runs/start', [RouteRunController::class, 'start']);
    Route::post('runs/finish', [RouteRunController::class, 'finish']);

    Route::get('garbage-points', [GarbageController::class, 'points']);
});

/*
|--------------------------------------------------------------------------
| Usuario autenticado (cualquier rol): ubicaciones y notificaciones
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('me')->group(function () {
    Route::get('locations', [UserLocationController::class, 'index']);
    Route::post('locations', [UserLocationController::class, 'store']);
    Route::get('locations/{id}', [UserLocationController::class, 'show'])->whereNumber('id');
    Route::put('locations/{id}', [UserLocationController::class, 'update'])->whereNumber('id');
    Route::delete('locations/{id}', [UserLocationController::class, 'destroy'])->whereNumber('id');
    Route::patch('locations/{id}/notifications', [UserLocationController::class, 'notifications'])->whereNumber('id');
    Route::patch('locations/{id}/garbage', [UserLocationController::class, 'garbage'])->whereNumber('id');

    Route::get('notifications/settings', [NotificationController::class, 'settings']);
    Route::patch('notifications/settings', [NotificationController::class, 'updateSettings']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read']);

    Route::post('push-subscriptions', [NotificationController::class, 'subscribe']);
    Route::delete('push-subscriptions', [NotificationController::class, 'unsubscribe']);
    Route::post('push-subscriptions/test', [NotificationController::class, 'test']);
});

/*
|--------------------------------------------------------------------------
| Device routes — authenticated with X-API-Key header
|--------------------------------------------------------------------------
*/
Route::middleware('api.key')->prefix('collector')->group(function () {
    Route::post('ping', [CollectorController::class, 'ping']);
});

/*
|--------------------------------------------------------------------------
| Admin routes — role: admin
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {

    // --- API keys (device authentication) ---
    Route::apiResource('api-keys', ApiKeyController::class)->parameters(['api-keys' => 'apiKey']);
    Route::post('api-keys/{apiKey}/regenerate', [ApiKeyController::class, 'regenerate']);

    // --- Neighborhood catalog ---
    Route::apiResource('neighborhoods', NeighborhoodController::class);
    Route::post('neighborhoods/bulk', [NeighborhoodController::class, 'bulkStore']);

    // --- Route management ---
    Route::apiResource('routes', RouteController::class);
    Route::prefix('routes/{route}')->group(function () {
        // Neighborhood stops
        Route::post('points', [RouteController::class, 'storePoint']);
        Route::put('points/bulk', [RouteController::class, 'bulkPoints']);
        Route::put('points/{point}', [RouteController::class, 'updatePoint']);
        Route::delete('points/{point}', [RouteController::class, 'destroyPoint']);

        Route::get('geometry', [RouteGeometryController::class, 'show']);
        Route::put('geometry', [RouteGeometryController::class, 'store']);
        Route::post('geometry/from-tracking', [RouteGeometryController::class, 'fromTracking']);
        Route::delete('geometry', [RouteGeometryController::class, 'destroy']);

        // Weekly schedule for this route
        // GET    → full week view (all 7 days, null if not configured)
        // POST   → add or update a single day
        // PUT    → configure multiple days (existing days not included are kept)
        // PATCH  → replace the entire week (days not in payload are deleted)
        Route::get('schedules', [ScheduleController::class, 'index']);
        Route::post('schedules', [ScheduleController::class, 'store']);
        Route::put('schedules', [ScheduleController::class, 'bulkStore']);
        Route::patch('schedules', [ScheduleController::class, 'replaceAll']);
        Route::put('schedules/{schedule}', [ScheduleController::class, 'update']);
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy']);
    });

    // --- Collector management ---
    // Collector users (role = collector); their id is the collector_id used everywhere
    Route::apiResource('collectors', CollectorUserController::class);
    Route::post('collector/assign-route', [CollectorController::class, 'assignRoute']);
    Route::get('collector/{collectorId}/routes', [CollectorController::class, 'collectorRoutes']);

    // --- Tracking & reports ---
    Route::get('tracking/today', [CollectorController::class, 'todayTracking']);
    Route::get('collector/{collectorId}/report', [CollectorController::class, 'routeReport']);
    Route::get('collector/{collectorId}/raw-tracking', [CollectorController::class, 'rawTracking']);

    Route::get('runs', [RouteRunController::class, 'index']);
    Route::get('runs/{run}', [RouteRunController::class, 'show']);
    Route::post('runs/{run}/finish', [RouteRunController::class, 'adminFinish']);
    Route::get('collectors/{collector}/day-track', [RouteRunController::class, 'dayTrack'])->whereNumber('collector');
    Route::get('garbage-points', [GarbageController::class, 'points']);
});
