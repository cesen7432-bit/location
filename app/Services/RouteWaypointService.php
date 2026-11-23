<?php

namespace App\Services;

use App\Models\Route;
use App\Models\RouteRun;
use App\Models\RouteWaypoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Puntos especiales del trazado (inicio, pausas, relleno sanitario, reanudaciones, fin)
 * y seguimiento de los viajes al relleno durante un recorrido.
 */
class RouteWaypointService
{
    // Distancia para considerar que el camión llegó a un punto especial
    public const ARRIVAL_RADIUS_M = 150;

    // Si no se detecta el regreso, el viaje al relleno se da por terminado tras este tiempo
    public const DETOUR_MAX_MIN = 180;

    public const DETOUR_PAUSE    = 'pause';
    public const DETOUR_LANDFILL = 'landfill';

    public function __construct(private readonly RouteSimplificationService $simplifier) {}

    public static function forget(int $routeId): void
    {
        Cache::store(config('cache.hot_store'))->forget(self::cacheKey($routeId));
    }

    private static function cacheKey(int $routeId): string
    {
        return "route-waypoints:{$routeId}";
    }

    /** @return array<int, array{type: string, lat: float, lng: float}> */
    public function cached(int $routeId): array
    {
        return Cache::store(config('cache.hot_store'))->rememberForever(self::cacheKey($routeId), fn () => RouteWaypoint::where('route_id', $routeId)
            ->orderBy('sequence')
            ->get(['type', 'lat', 'lng'])
            ->map(fn (RouteWaypoint $w) => ['type' => $w->type, 'lat' => $w->lat, 'lng' => $w->lng])
            ->all());
    }

    public function summary(Route $route): array
    {
        $waypoints = $route->waypoints()->get();

        return [
            'waypoints'      => $waypoints->map(fn (RouteWaypoint $w) => $w->toApi())->values(),
            'landfill_trips' => $waypoints->where('type', 'landfill')->count(),
        ];
    }

    /** Reemplaza la lista completa. Una lista vacía borra todos. */
    public function replace(Route $route, array $items): void
    {
        $items = $this->normalize($items);
        $this->validateOrder($items);

        DB::transaction(function () use ($route, $items) {
            $route->waypoints()->delete();

            $now = now();
            $rows = array_map(fn (array $w, int $i) => [
                'route_id'   => $route->id,
                'sequence'   => $i + 1,
                'type'       => $w['type'],
                'lat'        => $w['lat'],
                'lng'        => $w['lng'],
                'notes'      => $w['notes'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $items, array_keys($items));

            if ($rows) {
                RouteWaypoint::insert($rows);
            }
        });

        self::forget($route->id);
    }

    /** Ordena por sequence (o por posición en el arreglo si falta). */
    private function normalize(array $items): array
    {
        $items = array_values($items);

        $sequences = array_filter(array_column($items, 'sequence'), fn ($s) => $s !== null);
        if (count($sequences) !== count(array_unique($sequences))) {
            throw new InvalidArgumentException('Hay puntos especiales con la misma secuencia.');
        }

        $keyed = array_map(fn (array $w, int $i) => [$w['sequence'] ?? $i + 1, $i, $w], $items, array_keys($items));
        usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($keyed, 2);
    }

    private function validateOrder(array $items): void
    {
        if (!$items) {
            return;
        }

        $types = array_column($items, 'type');
        $last  = count($types) - 1;

        if ($types[0] !== 'start') {
            throw new InvalidArgumentException('El primer punto especial debe ser el inicio del recorrido.');
        }

        $counts = array_count_values($types);
        if (($counts['start'] ?? 0) > 1) {
            throw new InvalidArgumentException('Solo puede haber un punto de inicio.');
        }
        if (($counts['end'] ?? 0) > 1) {
            throw new InvalidArgumentException('Solo puede haber un punto de fin.');
        }
        if (isset($counts['end']) && $types[$last] !== 'end') {
            throw new InvalidArgumentException('El punto de fin debe ser el último.');
        }

        $paused = false;
        foreach ($types as $i => $type) {
            if ($type === 'pause' || $type === 'landfill') {
                $paused = true;
            } elseif ($type === 'resume') {
                if (!$paused) {
                    throw new InvalidArgumentException('El punto ' . ($i + 1) . ' (reanudar) debe ir después de una pausa o del relleno sanitario.');
                }
                $paused = false;
            }
        }
    }

    /**
     * Sigue la ida y vuelta al relleno: pausa → relleno → reanudar.
     * Devuelve true mientras el camión está fuera del trazado a propósito.
     */
    public function trackDetour(RouteRun $run, float $lat, float $lng, bool $onRoute): bool
    {
        $waypoints = $this->cached($run->route_id);
        $state     = $run->detour_state;

        $landfills = array_filter($waypoints, fn ($w) => $w['type'] === 'landfill');
        $pauses    = array_filter($waypoints, fn ($w) => $w['type'] === 'pause');
        $resumes   = array_filter($waypoints, fn ($w) => $w['type'] === 'resume');

        if (!$landfills && !$pauses) {
            return false;
        }

        $nearLandfill = $this->isNear($landfills, $lat, $lng);
        $nearPause    = $this->isNear($pauses, $lat, $lng);

        // Solo se entra en pausa al llegar al punto, no al seguir dentro de su radio tras reanudar
        $cache       = Cache::store(config('cache.hot_store'));
        $nearKey     = "run-near-pause:{$run->id}";
        $wasNearPause = (bool) $cache->get($nearKey, false);
        $cache->put($nearKey, $nearPause, now()->addDay());

        $expired = $state !== null && $run->detour_started_at
            && $run->detour_started_at->lt(now()->subMinutes(self::DETOUR_MAX_MIN));

        $next = $state;
        $trip = false;

        if ($nearLandfill && $state !== self::DETOUR_LANDFILL) {
            $next = self::DETOUR_LANDFILL;
            $trip = true;
        } elseif ($expired) {
            $next = null;
        } elseif ($state === null && $nearPause && !$wasNearPause) {
            $next = self::DETOUR_PAUSE;
        } elseif ($state === self::DETOUR_LANDFILL && !$nearLandfill) {
            // Termina al llegar a un punto de reanudar; sin ellos, al volver al trazado
            $back = $resumes ? $this->isNear($resumes, $lat, $lng) : $onRoute;
            if ($back) {
                $next = null;
                // Evita que el punto de pausa (a menudo el mismo) abra otro viaje enseguida
                $cache->put($nearKey, true, now()->addDay());
            }
        }

        if ($next !== $state || $trip) {
            $run->forceFill([
                'detour_state'      => $next,
                'detour_started_at' => $next === null ? null : ($state === null ? now() : $run->detour_started_at),
                'landfill_trips'    => (int) $run->landfill_trips + ($trip ? 1 : 0),
            ])->saveQuietly();
        }

        return $next !== null;
    }

    private function isNear(array $waypoints, float $lat, float $lng): bool
    {
        foreach ($waypoints as $w) {
            if ($this->simplifier->haversineMeters($w['lat'], $w['lng'], $lat, $lng) <= self::ARRIVAL_RADIUS_M) {
                return true;
            }
        }

        return false;
    }
}
