<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Models\RoutePoint;
use App\Models\Schedule;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouteController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/api/admin/routes",
     *     tags={"Routes"},
     *     summary="Listar rutas",
     *     operationId="routesIndex",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id",            type="integer", example=1),
     *                 @OA\Property(property="name",          type="string",  example="Ruta Sur #1"),
     *                 @OA\Property(property="description",   type="string",  nullable=true),
     *                 @OA\Property(property="zone",          type="string",  nullable=true),
     *                 @OA\Property(property="active",        type="boolean", example=true),
     *                 @OA\Property(property="total_points",  type="integer", example=5),
     *                 @OA\Property(property="schedule_days", type="array",   @OA\Items(type="string", example="monday"))
     *             ))
     *         )
     *     )
     * )
     */
    public function index(): JsonResponse
    {
        $routes = Route::withCount('points')->with('schedules')->get()->map(fn (Route $r) => [
            'id'           => $r->id,
            'name'         => $r->name,
            'description'  => $r->description,
            'zone'         => $r->zone,
            'active'       => $r->active,
            'total_points' => $r->points_count,
            'schedule_days'=> $r->schedules->where('active', true)->pluck('day_of_week')->values(),
        ]);

        return $this->success($routes);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/routes",
     *     tags={"Routes"},
     *     summary="Crear ruta",
     *     operationId="routesStore",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name",             type="string",  example="Ruta Sur #1"),
     *             @OA\Property(property="description",      type="string",  nullable=true),
     *             @OA\Property(property="zone",             type="string",  nullable=true, example="Sur"),
     *             @OA\Property(property="active",           type="boolean", example=true),
     *             @OA\Property(property="neighborhood_ids", type="array",   nullable=true,
     *                 description="IDs de barrios en orden de recorrido",
     *                 @OA\Items(type="integer", example=3)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Ruta creada",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Route")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'description'        => 'nullable|string',
            'zone'               => 'nullable|string|max:100',
            'active'             => 'boolean',
            'neighborhood_ids'   => 'sometimes|array|min:1',
            'neighborhood_ids.*' => 'distinct|exists:neighborhoods,id',
        ]);

        $route = Route::create($data)->refresh();

        if (!empty($data['neighborhood_ids'])) {
            $points = array_map(
                fn ($seq, $neighborhoodId) => [
                    'route_id'        => $route->id,
                    'neighborhood_id' => $neighborhoodId,
                    'sequence'        => $seq + 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                array_keys($data['neighborhood_ids']),
                $data['neighborhood_ids']
            );
            RoutePoint::insert($points);
        }

        return $this->created($this->routeWithPoints($route));
    }

    /**
     * @OA\Get(
     *     path="/api/admin/routes/{route}",
     *     tags={"Routes"},
     *     summary="Ver ruta con puntos y horarios",
     *     operationId="routesShow",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Route")
     *         )
     *     ),
     *     @OA\Response(response=404, description="No encontrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function show(Route $route): JsonResponse
    {
        return $this->success($this->routeWithPoints($route));
    }

    /**
     * @OA\Put(
     *     path="/api/admin/routes/{route}",
     *     tags={"Routes"},
     *     summary="Actualizar ruta",
     *     operationId="routesUpdate",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name",        type="string",  example="Ruta Sur #1"),
     *             @OA\Property(property="description", type="string",  nullable=true),
     *             @OA\Property(property="zone",        type="string",  nullable=true, example="Sur"),
     *             @OA\Property(property="active",      type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Route")
     *         )
     *     ),
     *     @OA\Response(response=404, description="No encontrado",      @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validacion",@OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function update(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'zone'        => 'nullable|string|max:100',
            'active'      => 'boolean',
        ]);

        $route->update($data);

        return $this->success($this->routeWithPoints($route->fresh()));
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/routes/{route}",
     *     tags={"Routes"},
     *     summary="Eliminar ruta",
     *     operationId="routesDestroy",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Response(response=200, description="Eliminado", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="No encontrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function destroy(Route $route): JsonResponse
    {
        $route->delete();

        return $this->success(null, 'Ruta eliminada');
    }

    // -------------------------------------------------------------------------
    // Route points
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(
     *     path="/api/admin/routes/{route}/points",
     *     tags={"Routes"},
     *     summary="Agregar parada a una ruta",
     *     operationId="routesStorePoint",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"neighborhood_id","sequence"},
     *             @OA\Property(property="neighborhood_id", type="integer", example=5),
     *             @OA\Property(property="sequence",        type="integer", example=3),
     *             @OA\Property(property="notes",           type="string",  nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Punto creado",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/RoutePoint")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function storePoint(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate([
            'neighborhood_id' => ['required', 'exists:neighborhoods,id', Rule::unique('route_points')->where('route_id', $route->id)],
            'sequence'        => 'required|integer|min:1',
            'notes'           => 'nullable|string',
        ]);

        $point = $route->points()->create($data);

        return $this->created($point->load('neighborhood'));
    }

    /**
     * @OA\Put(
     *     path="/api/admin/routes/{route}/points/{point}",
     *     tags={"Routes"},
     *     summary="Actualizar parada de ruta",
     *     operationId="routesUpdatePoint",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="point", in="path", required=true, @OA\Schema(type="integer", example=2)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="neighborhood_id", type="integer", example=7),
     *             @OA\Property(property="sequence",        type="integer", example=2),
     *             @OA\Property(property="notes",           type="string",  nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/RoutePoint")
     *         )
     *     ),
     *     @OA\Response(response=404, description="No encontrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function updatePoint(Request $request, Route $route, RoutePoint $point): JsonResponse
    {
        abort_if($point->route_id !== $route->id, 404);

        $data = $request->validate([
            'neighborhood_id' => ['sometimes', 'exists:neighborhoods,id', Rule::unique('route_points')->where('route_id', $route->id)->ignore($point->id)],
            'sequence'        => 'sometimes|integer|min:1',
            'notes'           => 'nullable|string',
        ]);

        $point->update($data);

        return $this->success($point->fresh('neighborhood'));
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/routes/{route}/points/{point}",
     *     tags={"Routes"},
     *     summary="Eliminar parada de ruta",
     *     operationId="routesDestroyPoint",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="point", in="path", required=true, @OA\Schema(type="integer", example=2)),
     *     @OA\Response(response=200, description="Eliminado", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="No encontrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function destroyPoint(Route $route, RoutePoint $point): JsonResponse
    {
        abort_if($point->route_id !== $route->id, 404);

        $point->delete();

        return $this->success(null, 'Punto eliminado');
    }

    /**
     * @OA\Put(
     *     path="/api/admin/routes/{route}/points/bulk",
     *     tags={"Routes"},
     *     summary="Reemplazar todas las paradas de una ruta",
     *     description="Elimina todas las paradas existentes y las reemplaza con el nuevo orden de barrios.",
     *     operationId="routesBulkPoints",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"neighborhood_ids"},
     *             @OA\Property(property="neighborhood_ids", type="array",
     *                 @OA\Items(type="integer", example=3)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Route")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function bulkPoints(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate([
            'neighborhood_ids'   => 'required|array|min:1',
            'neighborhood_ids.*' => 'distinct|exists:neighborhoods,id',
        ]);

        $route->points()->delete();

        RoutePoint::insert(array_map(
            fn ($seq, $neighborhoodId) => [
                'route_id'        => $route->id,
                'neighborhood_id' => $neighborhoodId,
                'sequence'        => $seq + 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            array_keys($data['neighborhood_ids']),
            $data['neighborhood_ids']
        ));

        return $this->success($this->routeWithPoints($route->fresh()));
    }

    // -------------------------------------------------------------------------

    private function routeWithPoints(Route $route): array
    {
        $route->load([
            'points'    => fn ($q) => $q->with('neighborhood')->orderBy('sequence'),
            'schedules' => fn ($q) => $q->where('active', true),
        ]);

        return [
            'id'           => $route->id,
            'name'         => $route->name,
            'description'  => $route->description,
            'zone'         => $route->zone,
            'active'       => $route->active,
            'total_points' => $route->points->count(),
            'schedule'     => $route->schedules->map(fn (Schedule $s) => [
                'day'        => $s->day_of_week,
                'day_name'   => Schedule::DAYS_ES[$s->day_of_week],
                'start_time' => $s->start_time,
                'end_time'   => $s->end_time,
            ]),
            'points' => $route->points->map(fn ($p) => [
                'id'       => $p->id,
                'sequence' => $p->sequence,
                'notes'    => $p->notes,
                'neighborhood' => [
                    'id'           => $p->neighborhood->id,
                    'name'         => $p->neighborhood->name,
                    'municipality' => $p->neighborhood->municipality,
                    'zone'         => $p->neighborhood->zone,
                    'latitude'     => $p->neighborhood->latitude,
                    'longitude'    => $p->neighborhood->longitude,
                ],
            ]),
        ];
    }
}
