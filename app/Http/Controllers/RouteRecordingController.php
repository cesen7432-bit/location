<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Models\RouteRecording;
use App\Services\RouteGeometryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RouteRecordingController extends Controller
{
    use ApiResponse;

    private const MAX_POINTS_PER_REQUEST = 1000;

    public function __construct(private readonly RouteGeometryService $geometry) {}

    /**
     * @OA\Get(path="/api/admin/recordings", tags={"Routes"}, summary="Listar grabaciones",
     *     description="Útil para retomar una grabación abierta (status=recording).",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"recording","finished","cancelled"})),
     *     @OA\Parameter(name="route_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => 'nullable|in:recording,finished,cancelled',
            'route_id' => 'nullable|integer',
        ]);

        $items = RouteRecording::with('route')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('route_id'), fn ($q) => $q->where('route_id', $request->input('route_id')))
            ->latest('started_at')
            ->limit(50)
            ->get()
            ->map->toApi();

        return $this->success($items);
    }

    /**
     * @OA\Post(path="/api/admin/recordings", tags={"Routes"}, summary="Iniciar grabación (ruta nueva o existente)",
     *     description="Sin route_id la grabación crea una ruta nueva al finalizar. Con route_id reemplaza el trazado de esa ruta; si ya tiene una grabación abierta responde 409 con ella.",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="route_id", type="integer", nullable=true),
     *         @OA\Property(property="notes", type="string", nullable=true))),
     *     @OA\Response(response=201, description="Grabación iniciada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Ya hay una grabación abierta para la ruta", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     * @OA\Post(path="/api/admin/routes/{route}/recordings", tags={"Routes"}, summary="Iniciar grabación de una ruta existente",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=201, description="Grabación iniciada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Ya hay una grabación abierta", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id' => 'nullable|integer|exists:routes,id',
            'notes'    => 'nullable|string|max:1000',
        ]);

        return $this->open($request, isset($data['route_id']) ? Route::find($data['route_id']) : null, $data['notes'] ?? null);
    }

    public function startForRoute(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);

        return $this->open($request, $route, $data['notes'] ?? null);
    }

    private function open(Request $request, ?Route $route, ?string $notes): JsonResponse
    {
        if ($route) {
            $open = RouteRecording::where('route_id', $route->id)->where('status', RouteRecording::RECORDING)->first();

            if ($open) {
                return $this->error('Ya hay una grabación abierta para esta ruta', $open->load('route')->toApi(), 409);
            }
        }

        $recording = RouteRecording::create([
            'route_id'   => $route?->id,
            'user_id'    => $request->user()->id,
            'status'     => RouteRecording::RECORDING,
            'started_at'  => now(),
            'point_count' => 0,
            'notes'       => $notes,
        ]);

        return $this->created($recording->load('route')->toApi(), 'Grabación iniciada');
    }

    /**
     * @OA\Post(path="/api/admin/recordings/{recording}/points", tags={"Routes"}, summary="Enviar tramo de puntos",
     *     description="Hasta 1000 puntos por petición. Reenviar un tramo es seguro: los puntos con el mismo recorded_at no se duplican.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="recording", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"points"},
     *         @OA\Property(property="points", type="array", @OA\Items(type="object",
     *             @OA\Property(property="lat", type="number", example=-3.99312),
     *             @OA\Property(property="lng", type="number", example=-79.20441),
     *             @OA\Property(property="accuracy", type="number", example=6.5),
     *             @OA\Property(property="recorded_at", type="string", format="date-time", example="2026-11-09T13:00:05.120Z"))))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="La grabación no está abierta", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function addPoints(Request $request, RouteRecording $recording): JsonResponse
    {
        $request->validate([
            'points'               => 'required|array|min:1|max:' . self::MAX_POINTS_PER_REQUEST,
            'points.*.lat'         => 'required|numeric|between:-90,90',
            'points.*.lng'         => 'required|numeric|between:-180,180',
            'points.*.accuracy'    => 'nullable|numeric|min:0',
            'points.*.recorded_at' => 'required|date',
        ]);

        if (!$recording->isRecording()) {
            return $this->error('La grabación ya no está abierta', $recording->toApi(), 409);
        }

        $tz   = config('app.timezone');
        $rows = array_map(fn ($p) => [
            'route_recording_id' => $recording->id,
            'latitude'           => (float) $p['lat'],
            'longitude'          => (float) $p['lng'],
            'accuracy'           => isset($p['accuracy']) ? (float) $p['accuracy'] : null,
            'recorded_at'        => Carbon::parse($p['recorded_at'])->setTimezone($tz)->format('Y-m-d H:i:s.v'),
        ], $request->input('points'));

        $stored = DB::table('route_recording_points')->insertOrIgnore($rows);

        $recording->update(['point_count' => $recording->points()->count()]);

        return $this->success([
            'received'    => count($rows),
            'stored'      => $stored,
            'point_count' => $recording->point_count,
        ], 'Puntos recibidos');
    }

    /**
     * @OA\Get(path="/api/admin/recordings/{recording}", tags={"Routes"}, summary="Ver grabación",
     *     description="Estado y vista previa de la línea limpia y simplificada con los puntos recibidos hasta ahora.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="recording", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function show(RouteRecording $recording): JsonResponse
    {
        $line = $this->line($recording);

        return $this->success([
            'recording' => $recording->load('route')->toApi(),
            'preview'   => [
                'distance_m'   => count($line) >= 2 ? (int) round($this->geometry->prepare($line)['length']) : 0,
                'points_count' => count($line),
                'path'         => array_map(fn ($p) => ['lat' => $p[0], 'lng' => $p[1]], $line),
            ],
        ]);
    }

    /**
     * @OA\Post(path="/api/admin/recordings/{recording}/finish", tags={"Routes"}, summary="Finalizar grabación",
     *     description="Limpia los puntos, simplifica y guarda la línea. Si la grabación no tiene ruta, crea la ruta con los datos enviados (name obligatorio) y detecta los barrios por donde pasa. La ruta queda lista para asignarla a recolectores.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="recording", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="name", type="string", example="Ruta 4 - Sector Este"),
     *         @OA\Property(property="code", type="string", example="4"),
     *         @OA\Property(property="shift", type="string", enum={"day","night"}),
     *         @OA\Property(property="zone", type="string"),
     *         @OA\Property(property="description", type="string"),
     *         @OA\Property(property="detect_neighborhoods", type="boolean", example=true))),
     *     @OA\Response(response=200, description="Trazado guardado", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="La grabación no está abierta", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Puntos insuficientes o datos de la ruta inválidos", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function finish(Request $request, RouteRecording $recording): JsonResponse
    {
        $creating = $recording->route_id === null;

        $data = $request->validate([
            'name'                 => ($creating ? 'required' : 'prohibited') . '|string|max:255',
            'code'                 => 'nullable|string|max:20',
            'shift'                => 'nullable|in:day,night',
            'zone'                 => 'nullable|string|max:100',
            'description'          => 'nullable|string',
            'detect_neighborhoods' => 'sometimes|boolean',
        ]);

        if (!$recording->isRecording()) {
            return $this->error('La grabación ya no está abierta', $recording->toApi(), 409);
        }

        $line = $this->line($recording);

        if (count($line) < 2) {
            // La grabación sigue abierta para poder enviar más puntos
            return $this->error('No hay puntos válidos suficientes para crear el trazado', $recording->toApi(), 422);
        }

        $route = DB::transaction(function () use ($recording, $creating, $data, $line, $request) {
            $route = $creating
                ? Route::create(array_intersect_key($data, array_flip(['name', 'code', 'shift', 'zone', 'description'])) + ['active' => true])
                : $recording->route;

            $this->geometry->saveGeometry($route, $line, 'recording');

            // En una ruta nueva se detectan los barrios por defecto; en una existente solo si se pide
            if ($request->boolean('detect_neighborhoods', $creating)) {
                $this->geometry->detectNeighborhoods($route);
            }

            $recording->update([
                'route_id'    => $route->id,
                'status'      => RouteRecording::FINISHED,
                'finished_at' => now(),
            ]);

            return $route;
        });

        $route->load(['points' => fn ($q) => $q->with('neighborhood:id,name')]);

        return $this->success([
            'recording'     => $recording->fresh('route')->toApi(),
            'route_created' => $creating,
            'route'         => [
                'id'            => $route->id,
                'name'          => $route->name,
                'code'          => $route->code,
                'shift'         => $route->shift,
                'zone'          => $route->zone,
                'active'        => $route->active,
                'neighborhoods' => $route->points->map(fn ($p) => [
                    'sequence' => $p->sequence,
                    'id'       => $p->neighborhood_id,
                    'name'     => $p->neighborhood?->name,
                ])->values(),
            ],
            'geometry' => $this->geometry->summary($route->fresh()),
        ], $creating ? 'Ruta creada desde la grabación' : 'Trazado guardado desde la grabación');
    }

    /**
     * @OA\Delete(path="/api/admin/recordings/{recording}", tags={"Routes"}, summary="Cancelar grabación",
     *     description="Descarta la grabación y sus puntos. No crea ni modifica rutas.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="recording", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function cancel(RouteRecording $recording): JsonResponse
    {
        if (!$recording->isRecording()) {
            return $this->error('La grabación ya no está abierta', $recording->toApi(), 409);
        }

        DB::table('route_recording_points')->where('route_recording_id', $recording->id)->delete();
        $recording->update(['status' => RouteRecording::CANCELLED, 'finished_at' => now(), 'point_count' => 0]);

        return $this->success($recording->load('route')->toApi(), 'Grabación cancelada');
    }

    private function line(RouteRecording $recording): array
    {
        $points = $recording->points()->get()->map(fn ($p) => [
            'lat'      => (float) $p->latitude,
            'lng'      => (float) $p->longitude,
            'accuracy' => $p->accuracy !== null ? (float) $p->accuracy : null,
            'time'     => Carbon::parse($p->recorded_at),
        ])->all();

        return $this->geometry->simplifyLatLng($this->geometry->cleanPoints($points));
    }
}
