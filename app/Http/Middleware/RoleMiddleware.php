<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    use ApiResponse;

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles)) {
            return $this->forbidden('No tienes permiso para acceder a este recurso');
        }

        if (!$user->active) {
            return $this->forbidden('Tu cuenta está desactivada');
        }

        return $next($request);
    }
}
