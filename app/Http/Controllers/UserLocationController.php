<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\UserLocation;
use App\Services\RouteGeometryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserLocationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RouteGeometryService $geometry) {}

    /**
     * @OA\Get(path="/api/me/locations", tags={"Me"}, summary="Mis ubicaciones",
     *     description="Cada ubicación incluye las rutas que pasan por ahí (a <= 150 m) con su horario.",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $locations = $request->user()->locations()
            ->with($this->relations())
            ->orderBy('id')
            ->get()
            ->map(fn (UserLocation $l) => $this->resource($l));

        return $this->success($locations);
    }

    /**
     * @OA\Post(path="/api/me/locations", tags={"Me"}, summary="Agregar ubicación",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"label","latitude","longitude"},
     *         @OA\Property(property="label", type="string", example="Casa"),
     *         @OA\Property(property="address", type="string", nullable=true, example="Av. Universitaria y Mercadillo"),
     *         @OA\Property(property="latitude", type="number", example=-3.99312),
     *         @OA\Property(property="longitude", type="number", example=-79.20441),
     *         @OA\Property(property="notify_enabled", type="boolean", example=true),
     *         @OA\Property(property="notify_distance_m", type="integer", example=500, minimum=100, maximum=2000))),
     *     @OA\Response(response=201, description="Creada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validación", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        $location = $request->user()->locations()->create($data);
        $this->geometry->matchLocation($location);

        return $this->created($this->resource($location->fresh($this->relations())), 'Ubicación agregada');
    }

    /**
     * @OA\Get(path="/api/me/locations/{id}", tags={"Me"}, summary="Detalle de ubicación",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="No encontrada", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->success($this->resource($this->find($request, $id)));
    }

    /**
     * @OA\Put(path="/api/me/locations/{id}", tags={"Me"}, summary="Editar ubicación",
     *     description="Si cambian las coordenadas se recalculan las rutas que pasan por ahí.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="label", type="string"),
     *         @OA\Property(property="address", type="string", nullable=true),
     *         @OA\Property(property="latitude", type="number"),
     *         @OA\Property(property="longitude", type="number"),
     *         @OA\Property(property="notify_enabled", type="boolean"),
     *         @OA\Property(property="notify_distance_m", type="integer"))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $location = $this->find($request, $id);
        $data     = $request->validate($this->rules(false));

        $location->update($data);

        if ($location->wasChanged(['latitude', 'longitude'])) {
            $this->geometry->matchLocation($location);
        }

        return $this->success($this->resource($location->fresh($this->relations())), 'Ubicación actualizada');
    }

    /**
     * @OA\Delete(path="/api/me/locations/{id}", tags={"Me"}, summary="Eliminar ubicación",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return $this->success(null, 'Ubicación eliminada');
    }

    /**
     * @OA\Patch(path="/api/me/locations/{id}/notifications", tags={"Me"}, summary="Activar/desactivar avisos de una ubicación",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"enabled"},
     *         @OA\Property(property="enabled", type="boolean", example=true),
     *         @OA\Property(property="distance_m", type="integer", example=500, minimum=100, maximum=2000))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function notifications(Request $request, int $id): JsonResponse
    {
        $location = $this->find($request, $id);

        $data = $request->validate([
            'enabled'    => 'required|boolean',
            'distance_m' => 'sometimes|integer|between:' . UserLocation::MIN_NOTIFY_DISTANCE . ',' . UserLocation::MAX_NOTIFY_DISTANCE,
        ]);

        $location->update(array_filter([
            'notify_enabled'    => $data['enabled'],
            'notify_distance_m' => $data['distance_m'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->success(
            $this->resource($location->fresh($this->relations())),
            $data['enabled'] ? 'Avisos activados' : 'Avisos desactivados'
        );
    }

    /**
     * @OA\Patch(path="/api/me/locations/{id}/garbage", tags={"Me"}, summary="Marcar si saqué la basura",
     *     description="out=true: la basura está afuera. Se limpia sola cuando el camión pasa por la ubicación durante un recorrido, o a las 24 h.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"out"}, @OA\Property(property="out", type="boolean", example=true))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function garbage(Request $request, int $id): JsonResponse
    {
        $location = $this->find($request, $id);
        $data     = $request->validate(['out' => 'required|boolean']);

        $location->update([
            'garbage_out'    => $data['out'],
            'garbage_out_at' => $data['out'] ? now() : $location->garbage_out_at,
        ]);

        return $this->success(
            $this->resource($location->fresh($this->relations())),
            $data['out'] ? 'Basura marcada como afuera' : 'Basura marcada como adentro'
        );
    }

    private function find(Request $request, int $id): UserLocation
    {
        return $request->user()->locations()->with($this->relations())->findOrFail($id);
    }

    private function relations(): array
    {
        return [
            'routeMatches.route' => fn ($q) => $q->select('id', 'name', 'code', 'zone', 'active'),
            'routeMatches.route.schedules' => fn ($q) => $q->where('active', true),
        ];
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'label'             => "{$req}|string|max:100",
            'address'           => 'sometimes|nullable|string|max:255',
            'latitude'          => "{$req}|numeric|between:-90,90",
            'longitude'         => "{$req}|numeric|between:-180,180",
            'notify_enabled'    => 'sometimes|boolean',
            'notify_distance_m' => 'sometimes|integer|between:' . UserLocation::MIN_NOTIFY_DISTANCE . ',' . UserLocation::MAX_NOTIFY_DISTANCE,
        ];
    }

    private function resource(UserLocation $location): array
    {
        $routes = $location->routeMatches
            ->filter(fn ($m) => $m->route && $m->route->active)
            ->map(fn ($m) => [
                'id'                  => $m->route->id,
                'name'                => $m->route->name,
                'code'                => $m->route->code,
                'zone'                => $m->route->zone,
                'distance_to_route_m' => round($m->distance_m),
                'schedule'            => $m->route->schedules->map(fn (Schedule $s) => $s->toDayApi())->values(),
            ])
            ->values();

        return [
            'id'                => $location->id,
            'label'             => $location->label,
            'address'           => $location->address,
            'latitude'          => $location->latitude,
            'longitude'         => $location->longitude,
            'notify_enabled'    => $location->notify_enabled,
            'notify_distance_m' => $location->notify_distance_m,
            'garbage'           => [
                'out'          => $location->hasGarbageOut(),
                'out_at'       => $location->garbage_out_at?->toIso8601String(),
                'collected_at' => $location->garbage_collected_at?->toIso8601String(),
            ],
            'served' => $routes->isNotEmpty(),
            'routes' => $routes,
        ];
    }
}
