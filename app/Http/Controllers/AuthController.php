<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Post(
     *     path="/api/auth/login",
     *     tags={"Auth"},
     *     summary="Iniciar sesión",
     *     description="Autentica un usuario o recolector y retorna un token Bearer Sanctum válido por 7 días.",
     *     operationId="authLogin",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email",    type="string", format="email", example="admin@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="secret")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Login exitoso",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="token",      type="string",  example="1|abc123"),
     *                 @OA\Property(property="token_type", type="string",  example="Bearer"),
     *                 @OA\Property(property="expires_in", type="integer", example=604800),
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id",    type="integer", example=1),
     *                     @OA\Property(property="name",  type="string",  example="Admin"),
     *                     @OA\Property(property="email", type="string",  example="admin@example.com"),
     *                     @OA\Property(property="role",  type="string",  example="admin", enum={"admin","collector","user"}),
     *                     @OA\Property(property="phone", type="string",  nullable=true,   example="+573001234567")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Credenciales incorrectas",    @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=403, description="Cuenta desactivada",          @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validación",         @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->unauthorized('Credenciales incorrectas');
        }

        if (!$user->active) {
            return $this->forbidden('Tu cuenta está desactivada');
        }

        $token = $user->createToken('api-token', ['*'], now()->addDays(7))->plainTextToken;

        return $this->success([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 7 * 24 * 60 * 60,
            'user'       => $this->userResource($user),
        ], 'Inicio de sesión exitoso');
    }

    /**
     * @OA\Get(
     *     path="/api/auth/me",
     *     tags={"Auth"},
     *     summary="Usuario autenticado",
     *     description="Retorna los datos del usuario dueño del token.",
     *     operationId="authMe",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id",    type="integer", example=1),
     *                 @OA\Property(property="name",  type="string",  example="Admin"),
     *                 @OA\Property(property="email", type="string",  example="admin@example.com"),
     *                 @OA\Property(property="role",  type="string",  example="admin"),
     *                 @OA\Property(property="phone", type="string",  nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="No autenticado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success($this->userResource($request->user()));
    }

    /**
     * @OA\Post(
     *     path="/api/auth/logout",
     *     tags={"Auth"},
     *     summary="Cerrar sesión",
     *     description="Invalida el token Sanctum actual.",
     *     operationId="authLogout",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="Sesión cerrada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=401, description="No autenticado",  @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Sesión cerrada exitosamente');
    }

    /**
     * @OA\Post(path="/api/auth/register", tags={"Auth"}, summary="Registro de usuario final",
     *     description="Crea una cuenta con rol user y devuelve el token, igual que el login. Máximo 5 intentos por minuto.",
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"name","email","password","password_confirmation"},
     *         @OA\Property(property="name", type="string", example="María López"),
     *         @OA\Property(property="email", type="string", format="email", example="maria@example.com"),
     *         @OA\Property(property="password", type="string", format="password", example="Secreta123"),
     *         @OA\Property(property="password_confirmation", type="string", format="password", example="Secreta123"),
     *         @OA\Property(property="phone", type="string", nullable=true, example="0991234567"))),
     *     @OA\Response(response=201, description="Cuenta creada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validación", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
            'phone'    => 'nullable|string|max:20',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],
            'phone'    => $data['phone'] ?? null,
            'role'     => 'user',
            'active'   => true,
        ])->refresh();

        $token = $user->createToken('api-token', ['*'], now()->addDays(7))->plainTextToken;

        return $this->created([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => 7 * 24 * 60 * 60,
            'user'       => $this->userResource($user),
        ], 'Cuenta creada');
    }

    /**
     * @OA\Put(path="/api/auth/me", tags={"Auth"}, summary="Actualizar mi perfil",
     *     description="Para cambiar la contraseña se exige current_password. Al cambiarla se cierran las demás sesiones.",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="email", type="string", format="email"),
     *         @OA\Property(property="phone", type="string", nullable=true),
     *         @OA\Property(property="current_password", type="string"),
     *         @OA\Property(property="password", type="string"),
     *         @OA\Property(property="password_confirmation", type="string"))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Error de validación", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     */
    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'email'            => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'            => 'sometimes|nullable|string|max:20',
            'current_password' => 'required_with:password|current_password:sanctum',
            'password'         => ['sometimes', 'confirmed', Password::min(8)],
        ]);

        unset($data['current_password']);
        $user->update($data);

        if (isset($data['password'])) {
            $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();
        }

        return $this->success($this->userResource($user->fresh()), 'Perfil actualizado');
    }

    /**
     * @OA\Delete(path="/api/auth/me", tags={"Auth"}, summary="Eliminar mi cuenta",
     *     description="Solo para usuarios finales. Borra la cuenta, sus ubicaciones, notificaciones y suscripciones push.",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"password"}, @OA\Property(property="password", type="string"))),
     *     @OA\Response(response=200, description="Cuenta eliminada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=403, description="Solo usuarios finales", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function deleteMe(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|current_password:sanctum']);

        $user = $request->user();

        // Borrar un recolector o admin eliminaría en cascada su historial de recorridos
        if (!$user->isEndUser()) {
            return $this->forbidden('Solo las cuentas de usuario final pueden eliminarse desde aquí');
        }

        $user->tokens()->delete();
        $user->notifications()->delete();
        $user->delete();

        return $this->success(null, 'Cuenta eliminada');
    }

    private function userResource(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
            'phone' => $user->phone,
            'notifications_enabled' => (bool) $user->notifications_enabled,
        ];
    }
}
