<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Base response for every API endpoint:
 *
 *   { "code": 1 | -1, "message": string, "result": mixed }
 *
 *  - code  1 → success, -1 → error (the HTTP status code is still set accordingly)
 *  - result → payload on success; on validation errors, the field → messages map; otherwise null
 */
trait ApiResponse
{
    private function body(int $code, string $message, mixed $result = null): array
    {
        return [
            'code'    => $code,
            'message' => $message,
            'result'  => $result,
        ];
    }

    protected function success(mixed $result = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json($this->body(1, $message, $result), $status);
    }

    protected function created(mixed $result = null, string $message = 'Creado exitosamente'): JsonResponse
    {
        return $this->success($result, $message, 201);
    }

    protected function error(string $message = 'Error', mixed $result = null, int $status = 400): JsonResponse
    {
        return response()->json($this->body(-1, $message, $result), $status);
    }

    protected function unauthorized(string $message = 'No autorizado'): JsonResponse
    {
        return $this->error($message, null, 401);
    }

    protected function forbidden(string $message = 'Acceso denegado'): JsonResponse
    {
        return $this->error($message, null, 403);
    }

    protected function notFound(string $message = 'Recurso no encontrado'): JsonResponse
    {
        return $this->error($message, null, 404);
    }

    protected function validationError(mixed $errors, string $message = 'Error de validación'): JsonResponse
    {
        return $this->error($message, $errors, 422);
    }
}
