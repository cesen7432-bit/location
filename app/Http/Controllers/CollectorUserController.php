<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Admin management of collector users (role = collector).
 * The resulting user id is the `collector_id` used in route assignments,
 * API keys and GPS pings.
 */
class CollectorUserController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/api/admin/collectors",
     *     tags={"Collectors"},
     *     summary="Listar recolectores",
     *     operationId="collectorsIndex",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="search", in="query", required=false, description="Nombre, email o teléfono", @OA\Schema(type="string")),
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

        $query = User::where('role', 'collector')->withCount('apiKeys')->orderBy('name');

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('phone', 'like', $term));
        }

        if ($request->has('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return $this->success($query->get()->map(fn (User $u) => $this->resource($u)));
    }

    /**
     * @OA\Post(
     *     path="/api/admin/collectors",
     *     tags={"Collectors"},
     *     summary="Crear recolector",
     *     description="Crea un usuario con rol collector. Con create_api_key=true genera además su API key (se muestra una sola vez).",
     *     operationId="collectorsStore",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"name","email","password"},
     *             @OA\Property(property="name",           type="string",  example="Carlos Pérez"),
     *             @OA\Property(property="email",          type="string",  example="carlos@ejemplo.com"),
     *             @OA\Property(property="password",       type="string",  example="Secreta123"),
     *             @OA\Property(property="phone",          type="string",  example="3104445566", nullable=true),
     *             @OA\Property(property="active",         type="boolean", example=true),
     *             @OA\Property(property="create_api_key", type="boolean", example=true),
     *             @OA\Property(property="api_key_name",   type="string",  example="GPS Camión 12", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Creado", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'required|email|max:255|unique:users,email',
            'password'       => ['required', 'string', Password::min(8)],
            'phone'          => 'nullable|string|max:20',
            'active'         => 'boolean',
            'create_api_key' => 'boolean',
            'api_key_name'   => 'nullable|string|max:100|unique:api_keys,name',
        ]);

        [$collector, $plainKey] = DB::transaction(function () use ($data, $request) {
            $collector = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => $data['password'], // hashed by the model cast
                'phone'    => $data['phone'] ?? null,
                'role'     => 'collector',
                'active'   => $data['active'] ?? true,
            ]);

            $plainKey = null;
            if (!empty($data['create_api_key'])) {
                $apiKey = new ApiKey([
                    'name'         => $data['api_key_name'] ?? "GPS {$collector->name} #{$collector->id}",
                    'collector_id' => $collector->id,
                ]);
                $apiKey->created_by = $request->user()->id;
                $plainKey = $apiKey->fillNewKey();
                $apiKey->save();
                $plainKey = ['id' => $apiKey->id, 'name' => $apiKey->name, 'key' => $plainKey];
            }

            return [$collector, $plainKey];
        });

        $result = $this->resource($collector->loadCount('apiKeys'));
        if ($plainKey) {
            $result['api_key'] = $plainKey;
        }

        return $this->created(
            $result,
            $plainKey ? 'Recolector creado con API key. Guarde la key, no se volverá a mostrar.' : 'Recolector creado'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/admin/collectors/{id}",
     *     tags={"Collectors"},
     *     summary="Ver recolector",
     *     description="Incluye sus API keys (sin la key completa).",
     *     operationId="collectorsShow",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="No encontrado")
     * )
     */
    public function show(int $collector): JsonResponse
    {
        $user = $this->findCollector($collector);
        $user->load('apiKeys:id,name,collector_id,key_prefix,active,last_used_at,expires_at')->loadCount('apiKeys');

        return $this->success($this->resource($user) + [
            'api_keys' => $user->apiKeys->map(fn (ApiKey $k) => [
                'id'           => $k->id,
                'name'         => $k->name,
                'key_prefix'   => $k->key_prefix,
                'active'       => $k->active,
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'expires_at'   => $k->expires_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/admin/collectors/{id}",
     *     tags={"Collectors"},
     *     summary="Actualizar recolector",
     *     description="Todos los campos opcionales. Si se desactiva, se cierran sus sesiones y sus API keys dejan de funcionar.",
     *     operationId="collectorsUpdate",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="name",     type="string"),
     *             @OA\Property(property="email",    type="string"),
     *             @OA\Property(property="password", type="string"),
     *             @OA\Property(property="phone",    type="string", nullable=true),
     *             @OA\Property(property="active",   type="boolean")
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function update(Request $request, int $collector): JsonResponse
    {
        $user = $this->findCollector($collector);

        $data = $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'email'    => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', Password::min(8)],
            'phone'    => 'sometimes|nullable|string|max:20',
            'active'   => 'sometimes|boolean',
        ]);

        $user->update($data);

        // Deactivated or password changed → close every open app session
        if ((array_key_exists('active', $data) && !$data['active']) || isset($data['password'])) {
            $user->tokens()->delete();
        }

        return $this->success($this->resource($user->loadCount('apiKeys')), 'Recolector actualizado');
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/collectors/{id}",
     *     tags={"Collectors"},
     *     summary="Eliminar recolector",
     *     description="Solo si no tiene historial de recorridos ni asignaciones. Si lo tiene, responde 409: desactívelo en su lugar.",
     *     operationId="collectorsDestroy",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Eliminado", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Tiene historial")
     * )
     */
    public function destroy(int $collector): JsonResponse
    {
        $user = $this->findCollector($collector);

        if ($user->tracking()->exists() || $user->collectorRoutes()->exists() || $user->sessions()->exists()) {
            return $this->error(
                'No se puede eliminar: el recolector tiene historial de rutas o recorridos. Desactívelo en su lugar.',
                null,
                409
            );
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->apiKeys()->delete();
            $user->delete();
        });

        return $this->success(null, 'Recolector eliminado');
    }

    // -------------------------------------------------------------------------

    private function findCollector(int $id): User
    {
        return User::where('role', 'collector')->findOrFail($id);
    }

    private function resource(User $u): array
    {
        return [
            'id'             => $u->id,
            'name'           => $u->name,
            'email'          => $u->email,
            'phone'          => $u->phone,
            'role'           => $u->role,
            'active'         => $u->active,
            'api_keys_count' => $u->api_keys_count ?? 0,
            'created_at'     => $u->created_at?->toIso8601String(),
        ];
    }
}
