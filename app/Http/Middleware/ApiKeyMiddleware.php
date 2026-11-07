<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request with the X-API-Key header.
 * If the key is linked to a collector, that collector becomes $request->user().
 */
class ApiKeyMiddleware
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->header('X-API-Key');

        if (!$plain) {
            return $this->unauthorized('API key requerida (header X-API-Key)');
        }

        $apiKey = ApiKey::with('collector')->where('key_hash', ApiKey::hashKey($plain))->first();

        if (!$apiKey || !$apiKey->active) {
            return $this->unauthorized('API key inválida o inactiva');
        }

        if ($apiKey->expires_at && $apiKey->expires_at->isPast()) {
            return $this->unauthorized('API key expirada');
        }

        if ($apiKey->collector && !$apiKey->collector->active) {
            return $this->forbidden('El recolector asociado a esta API key está desactivado');
        }

        // Avoid a write on every ping: refresh last_used_at at most once per minute
        if (!$apiKey->last_used_at || $apiKey->last_used_at->lt(now()->subMinute())) {
            $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_key', $apiKey);

        // The "user" of an API-key request is always the key's collector (or null),
        // never whatever another guard might resolve.
        $request->setUserResolver(fn () => $apiKey->collector);

        return $next($request);
    }
}
