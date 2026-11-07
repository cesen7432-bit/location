<?php

namespace App\Http\Controllers;

use App\Models\Neighborhood;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NeighborhoodController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/api/admin/neighborhoods",
     *     tags={"Neighborhoods"},
     *     summary="Listar barrios",
     *     description="Retorna el catálogo de barrios. Por defecto solo muestra activos.",
     *     operationId="neighborhoodsIndex",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="municipality", in="query", required=false, @OA\Schema(type="string", example="Bogotá")),
     *     @OA\Parameter(name="zone",         in="query", required=false, @OA\Schema(type="string", example="Sur")),
     *     @OA\Parameter(name="search",       in="query", required=false, description="Buscar por nombre", @OA\Schema(type="string", example="Centro")),
     *     @OA\Parameter(name="active",       in="query", required=false, description="Filtrar por activo (true/false)", @OA\Schema(type="boolean")),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Neighborhood"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'municipality' => 'nullable|string',
            'zone'         => 'nullable|string',
            'search'       => 'nullable|string|max:100',
            'active'       => 'nullable|boolean',
        ]);

        $query = Neighborhood::query();

        if ($request->filled('municipality')) {
            $query->where('municipality', 'like', '%' . $request->input('municipality') . '%');
        }

        if ($request->filled('zone')) {
            $query->where('zone', 'like', '%' . $request->input('zone') . '%');
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->has('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        } else {
            $query->where('active', true);
        }

        return $this->success($query->orderBy('municipality')->orderBy('zone')->orderBy('name')->get());
    }

    /**
     * @OA\Post(
     *     path="/api/admin/neighborhoods",
     *     tags={"Neighborhoods"},
     *     summary="Crear barrio",
     *     operationId="neighborhoodsStore",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"name","latitude","longitude"},
     *             @OA\Property(property="name",         type="string",  example="El Centro"),
     *             @OA\Property(property="municipality", type="string",  example="Bogotá",  nullable=true),
     *             @OA\Property(property="zone",         type="string",  example="Sur",     nullable=true),
     *             @OA\Property(property="latitude",     type="number",  format="float",    example=4.5981),
     *             @OA\Property(property="longitude",    type="number",  format="float",    example=-74.0758),
     *             @OA\Property(property="notes",        type="string",  nullable=true),
     *             @OA\Property(property="active",       type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Barrio creado",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Neighborhood")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validación", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'municipality' => 'nullable|string|max:150',
            'zone'         => 'nullable|string|max:150',
            'latitude'     => 'required|numeric|between:-90,90',
            'longitude'    => 'required|numeric|between:-180,180',
            'notes'        => 'nullable|string',
            'active'       => 'boolean',
        ]);

        $neighborhood = Neighborhood::create($data);

        return $this->created($neighborhood, 'Barrio creado exitosamente');
    }

    /**
     * @OA\Get(
     *     path="/api/admin/neighborhoods/{neighborhood}",
     *     tags={"Neighborhoods"},
     *     summary="Ver barrio",
     *     operationId="neighborhoodsShow",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="neighborhood", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Neighborhood")
     *         )
     *     ),
     *     @OA\Response(response=404, description="No encontrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function show(Neighborhood $neighborhood): JsonResponse
    {
        return $this->success($neighborhood->load('routePoints.route:id,name'));
    }

    /**
     * @OA\Put(
     *     path="/api/admin/neighborhoods/{neighborhood}",
     *     tags={"Neighborhoods"},
     *     summary="Actualizar barrio",
     *     operationId="neighborhoodsUpdate",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="neighborhood", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name",         type="string",  example="El Centro"),
     *             @OA\Property(property="municipality", type="string",  example="Bogotá",  nullable=true),
     *             @OA\Property(property="zone",         type="string",  example="Sur",     nullable=true),
     *             @OA\Property(property="latitude",     type="number",  format="float",    example=4.5981),
     *             @OA\Property(property="longitude",    type="number",  format="float",    example=-74.0758),
     *             @OA\Property(property="notes",        type="string",  nullable=true),
     *             @OA\Property(property="active",       type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Neighborhood")
     *         )
     *     ),
     *     @OA\Response(response=404, description="No encontrado",       @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validación", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function update(Request $request, Neighborhood $neighborhood): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'municipality' => 'nullable|string|max:150',
            'zone'         => 'nullable|string|max:150',
            'latitude'     => 'sometimes|numeric|between:-90,90',
            'longitude'    => 'sometimes|numeric|between:-180,180',
            'notes'        => 'nullable|string',
            'active'       => 'boolean',
        ]);

        $neighborhood->update($data);

        return $this->success($neighborhood->fresh());
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/neighborhoods/{neighborhood}",
     *     tags={"Neighborhoods"},
     *     summary="Eliminar barrio",
     *     description="Falla con 409 si el barrio está asignado a alguna ruta.",
     *     operationId="neighborhoodsDestroy",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="neighborhood", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Response(response=200, description="Eliminado",           @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="No encontrado",       @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Barrio en uso",       @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function destroy(Neighborhood $neighborhood): JsonResponse
    {
        if ($neighborhood->routePoints()->exists()) {
            return $this->error(
                'No se puede eliminar: el barrio está asignado a ' . $neighborhood->routePoints()->count() . ' ruta(s).',
                null,
                409
            );
        }

        $neighborhood->delete();

        return $this->success(null, 'Barrio eliminado');
    }

    /**
     * @OA\Post(
     *     path="/api/admin/neighborhoods/bulk",
     *     tags={"Neighborhoods"},
     *     summary="Importación masiva de barrios",
     *     description="Crea hasta 500 barrios en una sola transacción atómica.",
     *     operationId="neighborhoodsBulkStore",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"neighborhoods"},
     *             @OA\Property(property="neighborhoods", type="array", minItems=1, maxItems=500,
     *                 @OA\Items(
     *                     required={"name","latitude","longitude"},
     *                     @OA\Property(property="name",         type="string",  example="El Centro"),
     *                     @OA\Property(property="municipality", type="string",  nullable=true, example="Bogotá"),
     *                     @OA\Property(property="zone",         type="string",  nullable=true, example="Sur"),
     *                     @OA\Property(property="latitude",     type="number",  format="float", example=4.5981),
     *                     @OA\Property(property="longitude",    type="number",  format="float", example=-74.0758),
     *                     @OA\Property(property="notes",        type="string",  nullable=true)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Barrios creados",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="count",         type="integer", example=10),
     *                 @OA\Property(property="neighborhoods", type="array",   @OA\Items(ref="#/components/schemas/Neighborhood"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validación", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'neighborhoods'               => 'required|array|min:1|max:500',
            'neighborhoods.*.name'         => 'required|string|max:255',
            'neighborhoods.*.municipality' => 'nullable|string|max:150',
            'neighborhoods.*.zone'         => 'nullable|string|max:150',
            'neighborhoods.*.latitude'     => 'required|numeric|between:-90,90',
            'neighborhoods.*.longitude'    => 'required|numeric|between:-180,180',
            'neighborhoods.*.notes'        => 'nullable|string',
        ]);

        $created = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            return collect($data['neighborhoods'])->map(
                fn ($n) => Neighborhood::create(array_merge($n, ['active' => true]))
            );
        });

        return $this->created([
            'count'         => $created->count(),
            'neighborhoods' => $created,
        ], "Se crearon {$created->count()} barrios exitosamente");
    }
}
