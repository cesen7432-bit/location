<?php

namespace App\Http\Controllers;

use App\Models\CollectorSession;
use App\Models\CollectorTracking;
use App\Models\Neighborhood;
use App\Models\Route;
use App\Models\Schedule;
use App\Services\RouteSimplificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RouteSimplificationService $simplifier) {}

    /**
     * @OA\Get(
     *     path="/api/public/schedules",
     *     tags={"Public"},
     *     summary="Horarios de recolección activos",
     *     description="Retorna las rutas activas con sus horarios semanales. Sin autenticación. Filtros opcionales: ?day=monday &zone=sur",
     *     operationId="publicSchedules",
     *     @OA\Parameter(name="day",  in="query", required=false, description="Filtrar por día de la semana",
     *         @OA\Schema(type="string", enum={"monday","tuesday","wednesday","thursday","friday","saturday","sunday"})
     *     ),
     *     @OA\Parameter(name="zone", in="query", required=false, description="Filtrar por zona (búsqueda parcial)",
     *         @OA\Schema(type="string", example="Sur")
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id",           type="integer", example=1),
     *                 @OA\Property(property="name",         type="string",  example="Ruta Sur #1"),
     *                 @OA\Property(property="description",  type="string",  nullable=true),
     *                 @OA\Property(property="zone",         type="string",  example="Sur", nullable=true),
     *                 @OA\Property(property="total_points", type="integer", example=5),
     *                 @OA\Property(property="schedule",     type="array",   @OA\Items(
     *                     @OA\Property(property="day",        type="string", example="monday"),
     *                     @OA\Property(property="day_name",   type="string", example="Lunes"),
     *                     @OA\Property(property="start_time", type="string", example="07:00"),
     *                     @OA\Property(property="end_time",   type="string", example="09:00", nullable=true)
     *                 ))
     *             ))
     *         )
     *     )
     * )
     *
     * Active collection schedules — no auth required.
     * Returns routes with their weekly schedule.
     * Filterable by ?day=monday and/or ?zone=sur
     */
    public function schedules(Request $request): JsonResponse
    {
        $request->validate([
            'day'  => 'nullable|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'zone' => 'nullable|string|max:100',
        ]);

        $query = Route::with(['schedules' => fn ($q) => $q->where('active', true)])
            ->where('active', true);

        if ($request->filled('day')) {
            // Only routes that have an active schedule on this day
            $query->whereHas('schedules', fn ($q) => $q
                ->where('day_of_week', $request->input('day'))
                ->where('active', true)
            );
        }

        if ($request->filled('zone')) {
            $query->where('zone', 'like', '%' . $request->input('zone') . '%');
        }

        $routes = $query->withCount('points')->get()->map(fn (Route $route) => [
            'id'           => $route->id,
            'name'         => $route->name,
            'description'  => $route->description,
            'zone'         => $route->zone,
            'total_points' => $route->points_count,
            'schedule'     => $route->schedules->map(fn (Schedule $s) => [
                'day'        => $s->day_of_week,
                'day_name'   => Schedule::DAYS_ES[$s->day_of_week],
                'start_time' => $s->start_time,
                'end_time'   => $s->end_time,
            ]),
        ]);

        return $this->success($routes);
    }

    /**
     * @OA\Get(
     *     path="/api/public/neighborhoods/{neighborhood}/schedule",
     *     tags={"Public"},
     *     summary="Horario de recolección por barrio",
     *     description="Retorna el horario semanal de recolección para un barrio específico. Sin autenticación.",
     *     operationId="publicNeighborhoodSchedule",
     *     @OA\Parameter(name="neighborhood", in="path", required=true, description="ID del barrio",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="neighborhood", ref="#/components/schemas/Neighborhood"),
     *                 @OA\Property(property="collection_days", type="array", @OA\Items(type="string", example="monday")),
     *                 @OA\Property(property="weekly_schedule", type="object",
     *                     @OA\AdditionalProperties(type="object",
     *                         @OA\Property(property="day_name",       type="string",  example="Lunes"),
     *                         @OA\Property(property="has_collection", type="boolean", example=true),
     *                         @OA\Property(property="routes",         type="array",   @OA\Items(
     *                             @OA\Property(property="route_id",   type="integer", example=1),
     *                             @OA\Property(property="route_name", type="string",  example="Ruta Sur #1"),
     *                             @OA\Property(property="zone",       type="string",  example="Sur", nullable=true),
     *                             @OA\Property(property="start_time", type="string",  example="07:00"),
     *                             @OA\Property(property="end_time",   type="string",  example="09:00", nullable=true)
     *                         ))
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Barrio no encontrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     *
     * "¿Cuándo pasan los recolectores por mi barrio?" — no auth required.
     *
     * Traverses: neighborhood → route_points → routes → schedules
     * Returns every active schedule that covers this neighborhood,
     * grouped by day for easy consumption.
     */
    public function neighborhoodSchedule(Neighborhood $neighborhood): JsonResponse
    {
        if (!$neighborhood->active) {
            return $this->notFound('Barrio no disponible');
        }

        // Load routes that pass through this neighborhood, with their active schedules
        $routesThrough = Route::whereHas('points', fn ($q) => $q->where('neighborhood_id', $neighborhood->id))
            ->where('active', true)
            ->with(['schedules' => fn ($q) => $q->where('active', true)])
            ->get();

        // Build a day-keyed structure: which routes pass on each day
        $byDay = collect(Schedule::DAYS)->mapWithKeys(function (string $day) use ($routesThrough) {
            $routesOnDay = $routesThrough
                ->filter(fn (Route $r) => $r->schedules->contains('day_of_week', $day))
                ->map(function (Route $r) use ($day) {
                    $schedule = $r->schedules->firstWhere('day_of_week', $day);
                    return [
                        'route_id'   => $r->id,
                        'route_name' => $r->name,
                        'zone'       => $r->zone,
                        'start_time' => $schedule->start_time,
                        'end_time'   => $schedule->end_time,
                    ];
                })->values();

            return [$day => [
                'day_name' => Schedule::DAYS_ES[$day],
                'has_collection' => $routesOnDay->isNotEmpty(),
                'routes'   => $routesOnDay,
            ]];
        });

        return $this->success([
            'neighborhood' => [
                'id'           => $neighborhood->id,
                'name'         => $neighborhood->name,
                'municipality' => $neighborhood->municipality,
                'zone'         => $neighborhood->zone,
                'latitude'     => $neighborhood->latitude,
                'longitude'    => $neighborhood->longitude,
            ],
            'weekly_schedule' => $byDay,
            'collection_days' => $byDay->filter(fn ($d) => $d['has_collection'])->keys()->values(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/public/collectors/live",
     *     tags={"Public"},
     *     summary="Posiciones en vivo de recolectores",
     *     description="Retorna la posición actual de todos los recolectores activos hoy. Sin autenticación. Para tiempo real suscribirse al canal WebSocket `collectors.live`.",
     *     operationId="publicLiveCollectors",
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/CollectorSession"))
     *         )
     *     )
     * )
     *
     * Live collector positions — no auth required.
     * Reads from collector_sessions (one row per collector/route/day).
     * Use WebSocket channel collectors.live for real-time updates.
     */
    public function liveCollectors(): JsonResponse
    {
        $sessions = CollectorSession::with([
            'collector:id,name',
            'route:id,name,zone',
        ])
            ->where('tracking_date', today())
            ->where('status', '!=', 'completed')
            ->whereNotNull('current_latitude')
            ->orderBy('last_seen_at', 'desc')
            ->get()
            ->map(fn ($s) => [
                'collector_id'   => $s->collector_id,
                'collector_name' => $s->collector->name ?? null,
                'route'          => [
                    'id'   => $s->route_id,
                    'name' => $s->route->name ?? null,
                    'zone' => $s->route->zone ?? null,
                ],
                'latitude'     => $s->current_latitude,
                'longitude'    => $s->current_longitude,
                'status'       => $s->status,
                'last_seen_at' => $s->last_seen_at?->toIso8601String(),
            ]);

        return $this->success($sessions);
    }

    /**
     * @OA\Get(
     *     path="/api/public/routes/{route}/points",
     *     tags={"Public"},
     *     summary="Polilínea GPS planificada de una ruta",
     *     description="Retorna los puntos ordenados (barrios) de una ruta con sus coordenadas y horarios. Sin autenticación.",
     *     operationId="publicRoutePoints",
     *     @OA\Parameter(name="route", in="path", required=true, description="ID de la ruta",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="route", type="object",
     *                     @OA\Property(property="id",       type="integer", example=1),
     *                     @OA\Property(property="name",     type="string",  example="Ruta Sur #1"),
     *                     @OA\Property(property="zone",     type="string",  example="Sur", nullable=true),
     *                     @OA\Property(property="schedule", type="array",   @OA\Items(
     *                         @OA\Property(property="day",        type="string", example="monday"),
     *                         @OA\Property(property="day_name",   type="string", example="Lunes"),
     *                         @OA\Property(property="start_time", type="string", example="07:00"),
     *                         @OA\Property(property="end_time",   type="string", example="09:00", nullable=true)
     *                     ))
     *                 ),
     *                 @OA\Property(property="points", type="array", @OA\Items(
     *                     @OA\Property(property="sequence",     type="integer", example=1),
     *                     @OA\Property(property="neighborhood", type="string",  example="El Centro"),
     *                     @OA\Property(property="municipality", type="string",  nullable=true),
     *                     @OA\Property(property="zone",         type="string",  nullable=true),
     *                     @OA\Property(property="latitude",     type="number",  format="float", example=4.5981),
     *                     @OA\Property(property="longitude",    type="number",  format="float", example=-74.0758)
     *                 ))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Ruta no encontrada", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     *
     * Planned GPS points for a route (neighborhood polyline) — no auth required.
     */
    public function routePoints(Route $route): JsonResponse
    {
        if (!$route->active) {
            return $this->notFound('Ruta no disponible');
        }

        $route->load([
            'points'    => fn ($q) => $q->with('neighborhood')->orderBy('sequence'),
            'schedules' => fn ($q) => $q->where('active', true),
        ]);

        return $this->success([
            'route'  => [
                'id'       => $route->id,
                'name'     => $route->name,
                'zone'     => $route->zone,
                'schedule' => $route->schedules->map(fn (Schedule $s) => [
                    'day'        => $s->day_of_week,
                    'day_name'   => Schedule::DAYS_ES[$s->day_of_week],
                    'start_time' => $s->start_time,
                    'end_time'   => $s->end_time,
                ]),
            ],
            'points' => $route->points->map(fn ($p) => [
                'sequence'     => $p->sequence,
                'neighborhood' => $p->neighborhood->name ?? null,
                'municipality' => $p->neighborhood->municipality ?? null,
                'zone'         => $p->neighborhood->zone ?? null,
                'latitude'     => $p->neighborhood->latitude ?? null,
                'longitude'    => $p->neighborhood->longitude ?? null,
            ]),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/public/collectors/{collectorId}/path",
     *     tags={"Public"},
     *     summary="Ruta simplificada de un recolector (hoy)",
     *     description="Retorna el recorrido de hoy del recolector simplificado con Douglas-Peucker (ε=15m). Cargar al inicio y suscribirse al canal WebSocket `collectors.live` para agregar pings en tiempo real. Sin autenticación.",
     *     operationId="publicCollectorPath",
     *     @OA\Parameter(name="collectorId", in="path", required=true, description="ID del recolector",
     *         @OA\Schema(type="integer", example=3)
     *     ),
     *     @OA\Parameter(name="route_id", in="query", required=true, description="ID de la ruta",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="collector_id", type="integer", example=3),
     *                 @OA\Property(property="route_id",     type="integer", example=1),
     *                 @OA\Property(property="path",         type="array",   @OA\Items(
     *                     @OA\Property(property="lat", type="number", format="float", example=4.5981),
     *                     @OA\Property(property="lng", type="number", format="float", example=-74.0758)
     *                 )),
     *                 @OA\Property(property="point_count", type="integer", example=42),
     *                 @OA\Property(property="raw_count",   type="integer", example=380),
     *                 @OA\Property(property="last_ping_at",type="string",  format="date-time", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="route_id faltante", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     *
     * Today's simplified path for a collector — no auth required.
     * Load this first, then subscribe to WebSocket to append live pings.
     */
    public function collectorPath(Request $request, int $collectorId): JsonResponse
    {
        $request->validate([
            'route_id' => 'required|exists:routes,id',
        ]);

        $pings = CollectorTracking::where('collector_id', $collectorId)
            ->where('route_id', $request->input('route_id'))
            ->where('tracking_date', today())
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'recorded_at']);

        if ($pings->isEmpty()) {
            return $this->success([
                'collector_id' => $collectorId,
                'route_id'     => (int) $request->input('route_id'),
                'path'         => [],
                'point_count'  => 0,
            ]);
        }

        $points     = $pings->map(fn ($p) => ['lat' => $p->latitude, 'lng' => $p->longitude])->all();
        $simplified = $this->simplifier->simplify($points, 15.0);
        $lastPing   = $pings->last();

        return $this->success([
            'collector_id' => $collectorId,
            'route_id'     => (int) $request->input('route_id'),
            'path'         => $simplified,
            'point_count'  => count($simplified),
            'raw_count'    => $pings->count(),
            'last_ping_at' => $lastPing->recorded_at->toIso8601String(),
        ]);
    }
}
