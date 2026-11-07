<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/api/admin/api-keys",
     *     tags={"ApiKeys"},
     *     summary="Listar API keys",
     *     description="Lista las API keys (nunca retorna la key completa, solo su prefijo).",
     *     operationId="apiKeysIndex",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="search", in="query", required=false, description="Buscar por alias", @OA\Schema(type="string")),
     *     @OA\Parameter(name="active", in="query", required=false, @OA\Schema(type="boolean")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
            'active' => 'nullable|boolean',
        ]);

        $query = ApiKey::with('collector:id,name,email')->latest();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->has('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return $this->success($query->get());
    }

    /**
     * @OA\Post(
     *     path="/api/admin/api-keys",
     *     tags={"ApiKeys"},
     *     summary="Crear API key",
     *     description="Genera una nueva API key. La key completa se retorna UNA SOLA VEZ en el campo `key`; guárdela en un lugar seguro.",
     *     operationId="apiKeysStore",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name",         type="string",  example="GPS Camión 12"),
     *             @OA\Property(property="collector_id", type="integer", example=3, nullable=true, description="Recolector asociado. Si se omite, el ping debe enviar collector_id."),
     *             @OA\Property(property="expires_at",   type="string",  format="date-time", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Creada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:100|unique:api_keys,name',
            'collector_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'collector')],
            'expires_at'   => 'nullable|date|after:now',
        ]);

        $apiKey = new ApiKey($data);
        $apiKey->created_by = $request->user()->id;
        $plain = $apiKey->fillNewKey();
        $apiKey->save();

        return $this->created(
            array_merge($apiKey->load('collector:id,name,email')->toArray(), ['key' => $plain]),
            'API key creada. Guarde la key, no se volverá a mostrar.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/admin/api-keys/{id}",
     *     tags={"ApiKeys"},
     *     summary="Ver API key",
     *     operationId="apiKeysShow",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="No encontrada")
     * )
     */
    public function show(ApiKey $apiKey): JsonResponse
    {
        return $this->success($apiKey->load('collector:id,name,email'));
    }

    /**
     * @OA\Put(
     *     path="/api/admin/api-keys/{id}",
     *     tags={"ApiKeys"},
     *     summary="Actualizar API key",
     *     description="Cambia alias, recolector asociado, estado o expiración. No modifica la key.",
     *     operationId="apiKeysUpdate",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="name",         type="string",  example="GPS Camión 12"),
     *             @OA\Property(property="collector_id", type="integer", nullable=true),
     *             @OA\Property(property="active",       type="boolean"),
     *             @OA\Property(property="expires_at",   type="string",  format="date-time", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function update(Request $request, ApiKey $apiKey): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['sometimes', 'required', 'string', 'max:100', Rule::unique('api_keys', 'name')->ignore($apiKey->id)],
            'collector_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', 'collector')],
            'active'       => 'sometimes|boolean',
            'expires_at'   => 'sometimes|nullable|date',
        ]);

        $apiKey->update($data);

        return $this->success($apiKey->load('collector:id,name,email'), 'API key actualizada');
    }

    /**
     * @OA\Post(
     *     path="/api/admin/api-keys/{id}/regenerate",
     *     tags={"ApiKeys"},
     *     summary="Regenerar API key",
     *     description="Invalida la key actual y genera una nueva conservando alias y configuración. La nueva key se retorna una sola vez.",
     *     operationId="apiKeysRegenerate",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function regenerate(ApiKey $apiKey): JsonResponse
    {
        $plain = $apiKey->fillNewKey();
        $apiKey->last_used_at = null;
        $apiKey->save();

        return $this->success(
            array_merge($apiKey->load('collector:id,name,email')->toArray(), ['key' => $plain]),
            'API key regenerada. Guarde la key, no se volverá a mostrar.'
        );
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/api-keys/{id}",
     *     tags={"ApiKeys"},
     *     summary="Eliminar API key",
     *     operationId="apiKeysDestroy",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Eliminada", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function destroy(ApiKey $apiKey): JsonResponse
    {
        $apiKey->delete();

        return $this->success(null, 'API key eliminada');
    }
}
