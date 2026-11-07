<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'OK', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
            'code'    => $code,
        ], $code);
    }

    protected function created(mixed $data = null, string $message = 'Creado exitosamente'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function error(string $message = 'Error', mixed $errors = null, int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
            'code'    => $code,
        ], $code);
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
