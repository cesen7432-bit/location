<?php

namespace App\Http\Controllers;

use App\Models\CollectorRoute;
use App\Models\CollectorTracking;
use App\Models\Route;
use App\Models\RouteRun;
use App\Models\User;
use App\Services\CollectorTrackingService;
use App\Services\RouteGeometryService;
use App\Services\RouteSimplificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RouteRunController extends Controller
{
    use ApiResponse;

    private const REPORT_EPSILON_M = 8.0;

    public function __construct(
        private readonly CollectorTrackingService $tracking,
        private readonly RouteGeometryService $geometry,
        private readonly RouteSimplificationService $simplifier,
    ) {}

    /**
     * @OA\Post(path="/api/collector/runs/start", tags={"Collector"}, summary="Iniciar ruta",
     *     description="Marca el inicio de la ruta. Solo rutas asignadas al recolector para hoy (POST /api/admin/collector/assign-route). Si no se envian coordenadas se usa la ultima posicion del dispositivo (<= 10 min).",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"route_id"},
     *         @OA\Property(property="route_id", type="integer", example=1),
     *         @OA\Property(property="latitude", type="number", example=-3.99312),
     *         @OA\Property(property="longitude", type="number", example=-79.20441),
     *         @OA\Property(property="notes", type="string", nullable=true))),
     *     @OA\Response(response=201, description="Ruta iniciada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=403, description="Ruta no asignada para hoy", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Ya hay una ruta en curso", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id'  => 'required|integer|exists:routes,id',
            'latitude'  => 'nullable|required_with:longitude|numeric|between:-90,90',
            'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180',
            'notes'     => 'nullable|string|max:1000',
        ]);

        $collector = $request->user();
        $route     = Route::findOrFail($data['route_id']);

        if (!$route->active) {
            return $this->error('La ruta no está activa', null, 422);
        }

        if ($current = $this->tracking->activeRun($collector->id)) {
            return $this->error(
                'Ya tienes una ruta en curso. Finalízala antes de iniciar otra.',
                $current->load('route:id,name,code')->toApi(),
                409
            );
        }

        $assigned = CollectorRoute::where('collector_id', $collector->id)
            ->where('route_id', $route->id)
            ->whereDate('assigned_date', today())
            ->where('active', true)
            ->exists();

        if (!$assigned) {
            return $this->forbidden('Esta ruta no está asignada a ti para hoy');
        }

        $run = $this->tracking->startRun(
            $collector,
            $route,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            $data['notes'] ?? null,
        );

        return $this->created($run->load(['route:id,name,code', 'collector:id,name'])->toApi(), 'Ruta iniciada');
    }

    /**
     * @OA\Post(path="/api/collector/runs/finish", tags={"Collector"}, summary="Finalizar ruta",
     *     description="Cierra la ruta en curso del recolector. Calcula distancia recorrida y cantidad de pings.",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="latitude", type="number", example=-4.01025),
     *         @OA\Property(property="longitude", type="number", example=-79.19870),
     *         @OA\Property(property="notes", type="string", nullable=true))),
     *     @OA\Response(response=200, description="Ruta finalizada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="No hay ruta en curso", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function finish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude'  => 'nullable|required_with:longitude|numeric|between:-90,90',
            'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180',
            'notes'     => 'nullable|string|max:1000',
        ]);

        $run = $this->tracking->activeRun($request->user()->id);

        if (!$run) {
            return $this->notFound('No tienes una ruta en curso');
        }

        $run = $this->tracking->finishRun(
            $run,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            $data['notes'] ?? null,
            'collector',
        );

        return $this->success($run->load(['route:id,name,code', 'collector:id,name'])->toApi(), 'Ruta finalizada');
    }

    /**
     * @OA\Get(path="/api/collector/runs/current", tags={"Collector"}, summary="Ruta en curso",
     *     description="Devuelve la ruta en curso del recolector o null.", security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function current(Request $request): JsonResponse
    {
        $run = $this->tracking->activeRun($request->user()->id);

        return $this->success($run?->load(['route:id,name,code', 'collector:id,name'])->toApi());
    }

    /**
     * @OA\Get(path="/api/collector/runs", tags={"Collector"}, summary="Mis recorridos",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function mine(Request $request): JsonResponse
    {
        $request->validate(['date' => 'nullable|date']);

        $runs = RouteRun::with(['route:id,name,code', 'collector:id,name'])
            ->where('collector_id', $request->user()->id)
            ->when($request->filled('date'), fn ($q) => $q->whereDate('started_at', $request->input('date')))
            ->latest('started_at')
            ->limit(50)
            ->get()
            ->map->toApi();

        return $this->success($runs);
    }

    /**
     * @OA\Get(path="/api/admin/runs", tags={"Tracking"}, summary="Listar recorridos",
     *     description="Inicio (hora y ubicacion), fin (hora y ubicacion), duracion, distancia y avance de cada recorrido.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="collector_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="route_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"in_progress","completed"})),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", example=20)),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'collector_id' => 'nullable|integer',
            'route_id'     => 'nullable|integer',
            'date'         => 'nullable|date',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
            'status'       => 'nullable|in:in_progress,completed',
            'per_page'     => 'nullable|integer|min:1|max:100',
        ]);

        $page = RouteRun::with(['route:id,name,code', 'collector:id,name'])
            ->when($request->filled('collector_id'), fn ($q) => $q->where('collector_id', $request->input('collector_id')))
            ->when($request->filled('route_id'), fn ($q) => $q->where('route_id', $request->input('route_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereBetween('started_at', $this->dayRange($request->input('date'))))
            ->when($request->filled('from'), fn ($q) => $q->where('started_at', '>=', Carbon::parse($request->input('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('started_at', '<=', Carbon::parse($request->input('to'))->endOfDay()))
            ->latest('started_at')
            ->paginate((int) $request->input('per_page', 20));

        return $this->success([
            'items'      => collect($page->items())->map->toApi()->values(),
            'pagination' => [
                'page'      => $page->currentPage(),
                'per_page'  => $page->perPage(),
                'total'     => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @OA\Get(path="/api/admin/runs/{run}", tags={"Tracking"}, summary="Detalle de recorrido",
     *     description="Recorrido real (simplificado), trazado planificado de la ruta y estadisticas.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="run", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function show(RouteRun $run): JsonResponse
    {
        $run->load(['route', 'collector:id,name']);

        $pings = $run->pings()->orderBy('recorded_at')->get(['latitude', 'longitude', 'accuracy', 'recorded_at']);
        $path  = $this->simplifyPings($pings);

        return $this->success([
            'run'          => $run->toApi(),
            'actual_path'  => $path,
            'planned_path' => $run->route ? $this->geometry->path($run->route) : [],
            'stats'        => [
                'ping_count'         => $pings->count(),
                'path_point_count'   => count($path),
                'distance_m'         => $run->distance_m ?? (int) round($this->tracking->runDistance($run)),
                'planned_distance_m' => $run->route?->distance_m,
                'max_progress'       => $run->max_progress,
                'first_ping_at'      => $pings->first()?->recorded_at?->toIso8601String(),
                'last_ping_at'       => $pings->last()?->recorded_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @OA\Post(path="/api/admin/runs/{run}/finish", tags={"Tracking"}, summary="Cerrar recorrido olvidado",
     *     description="Cierra un recorrido que el recolector no finalizo. La hora y ubicacion de fin son las del ultimo ping del recorrido.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="run", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Ya finalizado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function adminFinish(Request $request, RouteRun $run): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);

        if (!$run->isInProgress()) {
            return $this->error('El recorrido ya está finalizado', null, 409);
        }

        $run = $this->tracking->finishRun($run, null, null, $data['notes'] ?? null, 'admin');

        return $this->success($run->load(['route:id,name,code', 'collector:id,name'])->toApi(), 'Recorrido finalizado por el administrador');
    }

    /**
     * @OA\Get(path="/api/admin/collectors/{collector}/day-track", tags={"Tracking"}, summary="Recorrido completo del dia",
     *     description="Todo lo que grabo el dispositivo en el dia, dividido en tramos: 'run' (durante una ruta) e 'idle' (fuera de ruta), con los puntos de inicio y fin de cada ruta.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="collector", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="date", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function dayTrack(Request $request, int $collector): JsonResponse
    {
        $request->validate(['date' => 'required|date']);

        $user = User::where('role', 'collector')->findOrFail($collector);
        $date = Carbon::parse($request->input('date'))->toDateString();

        $pings = CollectorTracking::where('collector_id', $user->id)
            ->where('tracking_date', $date)
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'accuracy', 'run_id', 'recorded_at']);

        $runs = RouteRun::with(['route:id,name,code', 'collector:id,name'])
            ->where('collector_id', $user->id)
            ->where(fn ($q) => $q
                ->whereBetween('started_at', $this->dayRange($date))
                ->orWhereIn('id', $pings->pluck('run_id')->filter()->unique()))
            ->orderBy('started_at')
            ->get();

        // Pings consecutivos con el mismo run_id forman un tramo
        $segments = [];
        foreach ($pings->chunkWhile(fn ($p, $k, $chunk) => $p->run_id === $chunk->last()->run_id) as $chunk) {
            $runId = $chunk->first()->run_id;
            $run   = $runId ? $runs->firstWhere('id', $runId) : null;
            $path  = $this->simplifyPings($chunk);

            $segments[] = [
                'type'       => $runId ? 'run' : 'idle',
                'run_id'     => $runId,
                'route'      => $run?->route ? ['id' => $run->route->id, 'name' => $run->route->name, 'code' => $run->route->code] : null,
                'from'       => $chunk->first()->recorded_at->toIso8601String(),
                'to'         => $chunk->last()->recorded_at->toIso8601String(),
                'ping_count' => $chunk->count(),
                'distance_m' => (int) round($this->tracking->pathDistance($path)),
                'path'       => $path,
            ];
        }

        $totalDistance = array_sum(array_column($segments, 'distance_m'));
        $runDistance   = array_sum(array_column(array_filter($segments, fn ($s) => $s['type'] === 'run'), 'distance_m'));

        return $this->success([
            'collector' => ['id' => $user->id, 'name' => $user->name],
            'date'      => $date,
            'summary'   => [
                'ping_count'          => $pings->count(),
                'first_ping_at'       => $pings->first()?->recorded_at?->toIso8601String(),
                'last_ping_at'        => $pings->last()?->recorded_at?->toIso8601String(),
                'total_distance_m'    => $totalDistance,
                'on_route_distance_m' => $runDistance,
                'runs_count'          => $runs->count(),
            ],
            'runs'     => $runs->map->toApi()->values(),
            'segments' => $segments,
        ]);
    }

    private function simplifyPings($pings): array
    {
        $points = $pings
            ->filter(fn ($p) => $p->accuracy === null || $p->accuracy <= CollectorTrackingService::MAX_PROGRESS_ACCURACY_M)
            ->map(fn ($p) => ['lat' => $p->latitude, 'lng' => $p->longitude])
            ->values()
            ->all();

        return array_values($this->simplifier->simplify($points, self::REPORT_EPSILON_M));
    }

    private function dayRange(string $date): array
    {
        $day = Carbon::parse($date);

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
    }
}
