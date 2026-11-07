<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

    private function userResource(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
            'phone' => $user->phone,
        ];
    }
}
