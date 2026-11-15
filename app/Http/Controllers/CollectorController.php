<?php

namespace App\Http\Controllers;

use App\Models\CollectorRoute;
use App\Models\CollectorSession;
use App\Models\CollectorTracking;
use App\Models\User;
use App\Services\CollectorTrackingService;
use App\Services\RouteSimplificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CollectorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RouteSimplificationService $simplifier,
        private readonly CollectorTrackingService $tracking,
    ) {}

    // -------------------------------------------------------------------------
    // Collector endpoints (role: collector)
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/api/collector/ping",
     *     tags={"Collector"},
     *     summary="Enviar ping GPS (dispositivo)",
     *     description="Autenticado con header X-API-Key. El dispositivo envia pings siempre que el camion esta encendido. Si el recolector tiene un recorrido en curso (POST /api/collector/runs/start), el ping se asocia a esa ruta, se calcula el avance y se avisa a los usuarios cercanos. Cada ping se emite por WebSocket (canal collectors.live, evento location.updated).",
     *     operationId="collectorPing",
     *     security={{"apiKey":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"latitude","longitude"},
     *             @OA\Property(property="latitude",  type="number", format="float", example=-3.99312),
     *             @OA\Property(property="longitude", type="number", format="float", example=-79.20441),
     *             @OA\Property(property="accuracy",  type="number", format="float", example=5.0, nullable=true),
     *             @OA\Property(property="collector_id", type="integer", example=3, description="Solo si la API key no tiene recolector asociado"),
     *             @OA\Property(property="route_id",  type="integer", nullable=true, description="Opcional (compatibilidad). Si hay recorrido en curso se usa su ruta.")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Ping registrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function ping(Request $request): JsonResponse
    {
        // Si la API key está asociada a un recolector, user() es ese recolector
        $keyCollector = $request->user();

        $data = $request->validate([
            'route_id'     => 'nullable|integer|exists:routes,id',
            'latitude'     => 'required|numeric|between:-90,90',
            'longitude'    => 'required|numeric|between:-180,180',
            'accuracy'     => 'nullable|numeric|min:0',
            'status'       => 'sometimes|in:on_route,paused,completed,idle',
            'collector_id' => [
                $keyCollector ? 'prohibited' : 'required',
                'integer',
                Rule::exists('users', 'id')->where('role', 'collector')->where('active', true),
            ],
        ]);

        $collector = $keyCollector ?? User::findOrFail($data['collector_id']);

        return $this->success($this->tracking->recordPing($collector, $data), 'Ping registrado');
    }

    /**
     * @OA\Get(
     *     path="/api/collector/my-routes",
     *     tags={"Collector"},
     *     summary="Rutas asignadas al recolector autenticado",
     *     description="Retorna las rutas activas asignadas para hoy (o la fecha indicada).",
     *     operationId="collectorMyRoutes",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="date", in="query", required=false, description="Fecha (Y-m-d). Default: hoy",
     *         @OA\Schema(type="string", format="date", example="2026-05-01")
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="date",   type="string", format="date", example="2026-05-01"),
     *                 @OA\Property(property="routes", type="array",  @OA\Items(type="object"))
     *             )
     *         )
     *     )
     * )
     *
     * Get routes assigned to the authenticated collector for today (or a given date).
     */
    public function myRoutes(Request $request): JsonResponse
    {
        $date = $request->query('date', today()->toDateString());

        $assignments = CollectorRoute::with(['route.points'])
            ->where('collector_id', $request->user()->id)
            ->where('assigned_date', $date)
            ->where('active', true)
            ->get();

        return $this->success([
            'date'   => $date,
            'routes' => $assignments,
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin endpoints
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/api/admin/collector/assign-route",
     *     tags={"Tracking"},
     *     summary="Asignar ruta a recolector",
     *     operationId="adminAssignRoute",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"collector_id","route_id","assigned_date"},
     *             @OA\Property(property="collector_id",  type="integer", example=3),
     *             @OA\Property(property="route_id",      type="integer", example=1),
     *             @OA\Property(property="assigned_date", type="string",  format="date", example="2026-05-01")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Ruta asignada",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id",             type="integer", example=10),
     *                 @OA\Property(property="collector_id",   type="integer", example=3),
     *                 @OA\Property(property="route_id",       type="integer", example=1),
     *                 @OA\Property(property="assigned_date",  type="string",  example="2026-05-01"),
     *                 @OA\Property(property="active",         type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="Usuario no es recolector", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validacion",      @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     *
     * Assign a route to a collector for a specific date.
     */
    public function assignRoute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collector_id'  => 'required|exists:users,id',
            'route_id'      => 'required|exists:routes,id',
            'assigned_date' => 'required|date',
        ]);

        $collector = User::findOrFail($data['collector_id']);

        if (!$collector->isCollector()) {
            return $this->error('El usuario indicado no tiene rol de recolector');
        }

        $assignment = CollectorRoute::updateOrCreate(
            [
                'collector_id'  => $data['collector_id'],
                'route_id'      => $data['route_id'],
                'assigned_date' => $data['assigned_date'],
            ],
            ['active' => true]
        );

        return $this->created(
            $assignment->load(['collector:id,name,phone', 'route:id,name,zone']),
            'Ruta asignada al recolector'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/admin/collector/{collectorId}/routes",
     *     tags={"Tracking"},
     *     summary="Rutas asignadas a un recolector (admin)",
     *     operationId="adminCollectorRoutes",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="collectorId", in="path", required=true, @OA\Schema(type="integer", example=3)),
     *     @OA\Parameter(name="date", in="query", required=false, description="Fecha (Y-m-d). Default: hoy",
     *         @OA\Schema(type="string", format="date", example="2026-05-01")
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="collector_id", type="integer", example=3),
     *                 @OA\Property(property="date",         type="string",  example="2026-05-01"),
     *                 @OA\Property(property="routes",       type="array",   @OA\Items(type="object"))
     *             )
     *         )
     *     )
     * )
     *
     * Get routes assigned to a specific collector on a date (admin view).
     */
    public function collectorRoutes(Request $request, int $collectorId): JsonResponse
    {
        $date = $request->query('date', today()->toDateString());

        $assignments = CollectorRoute::with(['route.points'])
            ->where('collector_id', $collectorId)
            ->where('assigned_date', $date)
            ->where('active', true)
            ->get();

        return $this->success([
            'collector_id' => $collectorId,
            'date'         => $date,
            'routes'       => $assignments,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/tracking/today",
     *     tags={"Tracking"},
     *     summary="Estado en vivo de todos los recolectores hoy (admin)",
     *     operationId="adminTodayTracking",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="collector_id",    type="integer", example=3),
     *                 @OA\Property(property="collector_name",  type="string",  example="Juan Perez"),
     *                 @OA\Property(property="collector_phone", type="string",  nullable=true),
     *                 @OA\Property(property="route",           type="object",
     *                     @OA\Property(property="id",   type="integer", example=1),
     *                     @OA\Property(property="name", type="string",  example="Ruta Sur #1"),
     *                     @OA\Property(property="zone", type="string",  nullable=true)
     *                 ),
     *                 @OA\Property(property="latitude",    type="number",  format="float", nullable=true),
     *                 @OA\Property(property="longitude",   type="number",  format="float", nullable=true),
     *                 @OA\Property(property="status",      type="string",  example="on_route"),
     *                 @OA\Property(property="started_at",  type="string",  format="date-time", nullable=true),
     *                 @OA\Property(property="last_seen_at",type="string",  format="date-time", nullable=true)
     *             ))
     *         )
     *     )
     * )
     *
     * Today's live status for all active collectors (admin dashboard).
     */
    public function todayTracking(): JsonResponse
    {
        $sessions = CollectorSession::with([
            'collector:id,name,phone',
            'route:id,name,code,zone',
        ])
            ->where('tracking_date', today())
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn ($s) => [
                'collector_id'   => $s->collector_id,
                'collector_name' => $s->collector->name ?? null,
                'collector_phone'=> $s->collector->phone ?? null,
                'route'          => $s->route_id ? [
                    'id'   => $s->route_id,
                    'name' => $s->route->name ?? null,
                    'code' => $s->route->code ?? null,
                    'zone' => $s->route->zone ?? null,
                ] : null,
                'run_id'         => $s->run_id,
                'latitude'       => $s->current_latitude,
                'longitude'      => $s->current_longitude,
                'status'         => $s->status,
                'progress'       => $s->progress,
                'off_route'      => (bool) $s->off_route,
                'distance_to_route_m' => $s->distance_to_route_m,
                'started_at'     => $s->started_at?->toIso8601String(),
                'last_seen_at'   => $s->last_seen_at?->toIso8601String(),
            ]);

        return $this->success($sessions);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/collector/{collectorId}/report",
     *     tags={"Tracking"},
     *     summary="Reporte de ruta de un recolector",
     *     description="Retorna ruta planificada vs recorrida real (simplificada con Douglas-Peucker) + estadisticas de distancia.",
     *     operationId="adminRouteReport",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="collectorId", in="path",  required=true, @OA\Schema(type="integer", example=3)),
     *     @OA\Parameter(name="route_id",    in="query", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="date",        in="query", required=true, @OA\Schema(type="string", format="date", example="2026-05-01")),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="collector_id",    type="integer", example=3),
     *                 @OA\Property(property="route",           type="object"),
     *                 @OA\Property(property="date",            type="string",  example="2026-05-01"),
     *                 @OA\Property(property="planned_path",    type="array",   @OA\Items(type="object")),
     *                 @OA\Property(property="simplified_path", type="array",   @OA\Items(
     *                     @OA\Property(property="lat", type="number", format="float"),
     *                     @OA\Property(property="lng", type="number", format="float")
     *                 )),
     *                 @OA\Property(property="stats", type="object",
     *                     @OA\Property(property="raw_ping_count",          type="integer", example=380),
     *                     @OA\Property(property="simplified_point_count",  type="integer", example=42),
     *                     @OA\Property(property="total_distance_meters",   type="number",  example=4250.5),
     *                     @OA\Property(property="planned_distance_meters", type="number",  example=3800.0)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     *
     * Full route report for a collector on a given day:
     * - Planned route (original GPS points)
     * - Full raw breadcrumb count
     * - Simplified path for display (Douglas-Peucker)
     * - Basic deviation stats
     */
    public function routeReport(Request $request, int $collectorId): JsonResponse
    {
        $request->validate([
            'route_id' => 'required|exists:routes,id',
            'date'     => 'required|date',
        ]);

        // Load points with their neighborhoods to get coordinates
        $route = \App\Models\Route::with(['points.neighborhood'])->findOrFail($request->input('route_id'));

        // Fetch all raw pings ordered by time
        $rawPings = CollectorTracking::where('collector_id', $collectorId)
            ->where('route_id', $request->input('route_id'))
            ->where('tracking_date', $request->input('date'))
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'accuracy', 'recorded_at']);

        $breadcrumbs = $rawPings->map(fn ($p) => [
            'lat'         => $p->latitude,
            'lng'         => $p->longitude,
            'accuracy'    => $p->accuracy,
            'recorded_at' => $p->recorded_at->toIso8601String(),
        ])->all();

        // Simplified path for map display (Douglas-Peucker, epsilon = 15m)
        $simplifiedPoints = $rawPings->map(fn ($p) => [
            'lat' => $p->latitude,
            'lng' => $p->longitude,
        ])->all();

        $simplified = $this->simplifier->simplify($simplifiedPoints, 15.0);

        // Planned path: coordinates come from the neighborhood catalog
        $plannedPoints = $route->points
            ->filter(fn ($p) => $p->neighborhood !== null)
            ->map(fn ($p) => [
                'sequence'  => $p->sequence,
                'name'      => $p->neighborhood->name,
                'lat'       => $p->neighborhood->latitude,
                'lng'       => $p->neighborhood->longitude,
            ])->values()->all();

        $plannedCoords   = array_map(fn ($p) => ['lat' => $p['lat'], 'lng' => $p['lng']], $plannedPoints);
        $plannedDistance = $this->calculateTotalDistance($plannedCoords);

        // Stats
        $totalDistanceMeters = $this->calculateTotalDistance($simplifiedPoints);

        return $this->success([
            'collector_id'    => $collectorId,
            'route'           => [
                'id'   => $route->id,
                'name' => $route->name,
                'zone' => $route->zone,
            ],
            'date'            => $request->input('date'),
            'planned_path'    => $plannedPoints,
            'simplified_path' => $simplified,
            'stats'           => [
                'raw_ping_count'          => count($breadcrumbs),
                'simplified_point_count'  => count($simplified),
                'total_distance_meters'   => round($totalDistanceMeters, 1),
                'planned_distance_meters' => round($plannedDistance, 1),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/collector/{collectorId}/raw-tracking",
     *     tags={"Tracking"},
     *     summary="Dump de breadcrumbs crudos",
     *     description="Todos los pings GPS sin simplificar para analisis forense o replay detallado.",
     *     operationId="adminRawTracking",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="collectorId", in="path",  required=true, @OA\Schema(type="integer", example=3)),
     *     @OA\Parameter(name="route_id",    in="query", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="date",        in="query", required=true, @OA\Schema(type="string", format="date", example="2026-05-01")),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="collector_id", type="integer", example=3),
     *                 @OA\Property(property="route_id",     type="integer", example=1),
     *                 @OA\Property(property="date",         type="string",  example="2026-05-01"),
     *                 @OA\Property(property="count",        type="integer", example=380),
     *                 @OA\Property(property="pings",        type="array",   @OA\Items(
     *                     @OA\Property(property="latitude",    type="number",  format="float"),
     *                     @OA\Property(property="longitude",   type="number",  format="float"),
     *                     @OA\Property(property="accuracy",    type="number",  nullable=true),
     *                     @OA\Property(property="recorded_at", type="string",  format="date-time")
     *                 ))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     *
     * Raw breadcrumb dump for forensic analysis / detailed replay.
     */
    public function rawTracking(Request $request, int $collectorId): JsonResponse
    {
        $request->validate([
            'route_id' => 'nullable|exists:routes,id',
            'date'     => 'required|date',
        ]);

        $pings = CollectorTracking::where('collector_id', $collectorId)
            ->when($request->filled('route_id'), fn ($q) => $q->where('route_id', $request->input('route_id')))
            ->where('tracking_date', $request->input('date'))
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'accuracy', 'route_id', 'run_id', 'recorded_at']);

        return $this->success([
            'collector_id' => $collectorId,
            'route_id'     => $request->filled('route_id') ? (int) $request->input('route_id') : null,
            'date'         => $request->input('date'),
            'count'        => $pings->count(),
            'pings'        => $pings,
        ]);
    }

    private function calculateTotalDistance(array $points): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $total += $this->simplifier->haversineMeters(
                $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                $points[$i]['lat'],     $points[$i]['lng']
            );
        }
        return $total;
    }
}
