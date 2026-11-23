<?php

namespace App\Services;

use App\Events\CollectorLocationUpdated;
use App\Models\CollectorSession;
use App\Models\CollectorTracking;
use App\Models\Route;
use App\Models\RouteRun;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * El dispositivo envía pings siempre que el camión está encendido; un ping pertenece
 * a una ruta solo mientras el recolector tiene un recorrido en curso.
 */
class CollectorTrackingService
{
    public const MAX_PROGRESS_ACCURACY_M = 50;

    public const DEVICE_LOCATION_MAX_AGE_MIN = 10;

    // ping_count y max_progress del recorrido se actualizan como mucho cada tanto, no en cada ping
    public const RUN_SYNC_INTERVAL_S = 30;

    // Pings a menos de esta distancia del último guardado (camión detenido) no se guardan
    public const MIN_MOVE_M = 2;

    public function __construct(
        private readonly RouteGeometryService $geometry,
        private readonly NearbyCollectorNotifier $notifier,
        private readonly RouteSimplificationService $simplifier,
        private readonly RouteWaypointService $waypoints,
    ) {}

    public function activeRun(int $collectorId): ?RouteRun
    {
        return RouteRun::where('collector_id', $collectorId)
            ->where('status', RouteRun::IN_PROGRESS)
            ->latest('started_at')
            ->first();
    }

    public function recordPing(User $collector, array $data): array
    {
        $now  = now();
        $lat  = (float) $data['latitude'];
        $lng  = (float) $data['longitude'];
        $run  = $this->activeRun($collector->id);

        // route_id explícito solo se respeta si no hay recorrido (clientes antiguos)
        $routeId = $run?->route_id ?? (isset($data['route_id']) ? (int) $data['route_id'] : null);

        $stored = $this->storePing($collector->id, $routeId, $run?->id, $lat, $lng, $data['accuracy'] ?? null, $now);

        $session = CollectorSession::firstOrNew([
            'collector_id'  => $collector->id,
            'tracking_date' => $now->toDateString(),
        ]);

        if (!$session->exists) {
            $session->started_at = $now;
        }

        if ((int) $session->run_id !== (int) $run?->id) {
            $session->fill([
                'along_m' => null, 'segment_index' => null, 'progress' => null,
                'distance_to_route_m' => null, 'off_route' => false, 'off_route_count' => 0,
            ]);
        }

        $previousAlong = $session->along_m;

        $session->fill([
            'route_id'          => $routeId,
            'run_id'            => $run?->id,
            'current_latitude'  => $lat,
            'current_longitude' => $lng,
            'status'            => $data['status'] ?? ($run ? 'on_route' : ($routeId ? 'on_route' : 'idle')),
            'last_seen_at'      => $now,
        ]);

        $projection = null;
        $detour     = false;
        $accurate   = ($data['accuracy'] ?? null) === null || $data['accuracy'] <= self::MAX_PROGRESS_ACCURACY_M;

        if ($run && $accurate) {
            $prepared   = $this->geometry->prepared($run->route_id);
            $projection = $prepared ? $this->geometry->project($prepared, $lat, $lng, $session->segment_index) : null;
            $onRoute    = $projection && $projection['distance_m'] <= RouteGeometryService::OFF_ROUTE_DISTANCE_M;
            $detour     = $this->waypoints->trackDetour($run, $lat, $lng, $onRoute);
        }

        if ($projection && $detour) {
            // Ida y vuelta al relleno: va fuera del trazado a propósito, no es desvío ni cambia el avance
            $session->fill([
                'distance_to_route_m' => $projection['distance_m'],
                'off_route_count'     => 0,
                'off_route'           => false,
            ]);
        } elseif ($projection) {
            $offCount   = $onRoute ? 0 : $session->off_route_count + 1;

            $session->fill([
                'distance_to_route_m' => $projection['distance_m'],
                'off_route_count'     => $offCount,
                'off_route'           => $offCount >= RouteGeometryService::OFF_ROUTE_PINGS,
            ]);

            // Solo los pings sobre la ruta hacen avanzar al camión
            if ($onRoute) {
                $session->fill([
                    'along_m'       => (int) round($projection['along_m']),
                    'segment_index' => $projection['segment_index'],
                    'progress'      => $projection['progress'],
                ]);

                if ($projection['progress'] > (float) $run->max_progress) {
                    $this->syncRun($run, $projection['progress']);
                }
            }
        }

        $session->save();

        $notify = $run && $projection && !$detour && !$session->off_route
            && $projection['distance_m'] <= RouteGeometryService::OFF_ROUTE_DISTANCE_M;
        $along = (float) $session->along_m;

        // Después de responder al dispositivo: el WebSocket no lo hace esperar
        defer(function () use ($collector, $session, $run, $now, $notify, $along, $previousAlong) {
            // Libera la conexión mientras se espera al servicio de WebSocket
            if (DB::getDriverName() !== 'sqlite') {
                DB::disconnect();
            }

            $this->broadcast($collector, $session, $run, $now);

            if (!$notify) {
                return;
            }

            try {
                $run->loadMissing('route:id,name');
                $this->notifier->notifyApproaching($run, $collector, $along);
                $this->notifier->markCollected($run, $previousAlong, $along);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return [
            'recorded'   => true,
            'stored'     => $stored,
            'session_id' => $session->id,
            'run_id'     => $run?->id,
            'route_id'   => $routeId,
            'progress'   => $session->progress,
            'off_route'  => (bool) $session->off_route,
            'on_landfill_trip' => $detour,
        ];
    }

    private function broadcast(User $collector, CollectorSession $session, ?RouteRun $run, CarbonInterface $now): bool
    {
        try {
            broadcast(new CollectorLocationUpdated([
                'collector_id'   => $collector->id,
                'collector_name' => $collector->name,
                'route_id'       => $session->route_id,
                'run_id'         => $run?->id,
                'latitude'       => $session->current_latitude,
                'longitude'      => $session->current_longitude,
                'status'         => $session->status,
                'progress'       => $session->progress,
                'off_route'      => (bool) $session->off_route,
                'on_landfill_trip' => $run !== null && $run->detour_state !== null,
                'landfill_trips' => $run ? (int) $run->landfill_trips : null,
                'last_seen_at'   => $now->toIso8601String(),
            ]));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Guarda el ping salvo que el camión siga en el mismo lugar que el último punto guardado. */
    private function storePing(int $collectorId, ?int $routeId, ?int $runId, float $lat, float $lng, ?float $accuracy, CarbonInterface $now): bool
    {
        $cache = Cache::store(config('cache.hot_store'));
        $key   = "last-stored-ping:{$collectorId}";
        $date  = $now->toDateString();
        $last  = $cache->get($key);

        // Cambio de recorrido o de día: siempre se guarda para marcar el corte
        if ($last && $last['run_id'] === $runId && $last['date'] === $date
            && $this->simplifier->haversineMeters($last['lat'], $last['lng'], $lat, $lng) < self::MIN_MOVE_M) {
            return false;
        }

        CollectorTracking::create([
            'collector_id'  => $collectorId,
            'route_id'      => $routeId,
            'run_id'        => $runId,
            'latitude'      => $lat,
            'longitude'     => $lng,
            'accuracy'      => $accuracy,
            'tracking_date' => $date,
            'recorded_at'   => $now,
        ]);

        $cache->put($key, ['lat' => $lat, 'lng' => $lng, 'run_id' => $runId, 'date' => $date], now()->addDay());

        return true;
    }

    private function syncRun(RouteRun $run, float $progress): void
    {
        if (!Cache::store(config('cache.hot_store'))->add("run-sync:{$run->id}", 1, self::RUN_SYNC_INTERVAL_S)) {
            return;
        }

        $run->forceFill([
            'max_progress' => $progress,
            'ping_count'   => $run->pings()->count(),
        ])->saveQuietly();
    }

    public function startRun(User $collector, Route $route, ?float $lat, ?float $lng, ?string $notes): RouteRun
    {
        $now = now();
        [$lat, $lng, $source] = $this->resolveLocation($collector->id, $lat, $lng);

        return DB::transaction(function () use ($collector, $route, $lat, $lng, $source, $notes, $now) {
            $run = RouteRun::create([
                'collector_id'    => $collector->id,
                'route_id'        => $route->id,
                'status'          => RouteRun::IN_PROGRESS,
                'started_at'      => $now,
                'start_latitude'  => $lat,
                'start_longitude' => $lng,
                'start_source'    => $source,
                'notes'           => $notes,
            ]);

            $session = CollectorSession::firstOrNew([
                'collector_id'  => $collector->id,
                'tracking_date' => $now->toDateString(),
            ]);

            $session->fill([
                'route_id' => $route->id, 'run_id' => $run->id, 'status' => 'on_route',
                'along_m' => null, 'segment_index' => null, 'progress' => null,
                'distance_to_route_m' => null, 'off_route' => false, 'off_route_count' => 0,
                'current_latitude'  => $session->current_latitude ?? $lat,
                'current_longitude' => $session->current_longitude ?? $lng,
                'started_at'        => $session->started_at ?? $now,
                'last_seen_at'      => $session->last_seen_at ?? $now,
            ])->save();

            return $run;
        });
    }

    public function finishRun(RouteRun $run, ?float $lat, ?float $lng, ?string $notes, string $closedBy): RouteRun
    {
        $lastPing = $run->pings()->latest('recorded_at')->latest('id')->first();

        if ($lat !== null && $lng !== null) {
            $source = 'app';
        } elseif ($closedBy === 'admin' && $lastPing) {
            // Recorrido olvidado: termina donde el dispositivo lo vio por última vez
            [$lat, $lng, $source] = [$lastPing->latitude, $lastPing->longitude, 'device'];
        } else {
            [$lat, $lng, $source] = $this->resolveLocation($run->collector_id, null, null);
        }

        $endedAt = $closedBy === 'admin' && $lastPing ? $lastPing->recorded_at : now();

        $lastProgress = CollectorSession::where('run_id', $run->id)->max('progress');

        $run->update([
            'status'        => RouteRun::COMPLETED,
            'ended_at'      => $endedAt,
            'end_latitude'  => $lat,
            'end_longitude' => $lng,
            'end_source'    => $source,
            'closed_by'     => $closedBy,
            'ping_count'    => $run->pings()->count(),
            'max_progress'  => max((float) $run->max_progress, (float) $lastProgress),
            'detour_state'      => null,
            'detour_started_at' => null,
            'distance_m'    => (int) round($this->runDistance($run)),
            'notes'         => $notes !== null ? trim(($run->notes ? $run->notes . "\n" : '') . $notes) : $run->notes,
        ]);

        CollectorSession::where('run_id', $run->id)->update([
            'run_id'              => null,
            'route_id'            => null,
            'status'              => 'idle',
            'along_m'             => null,
            'segment_index'       => null,
            'progress'            => null,
            'distance_to_route_m' => null,
            'off_route'           => false,
            'off_route_count'     => 0,
        ]);

        return $run->refresh();
    }

    /**
     * Coordenadas enviadas por la app o, si no hay, el último ping reciente del dispositivo.
     *
     * @return array{0: ?float, 1: ?float, 2: ?string}
     */
    private function resolveLocation(int $collectorId, ?float $lat, ?float $lng): array
    {
        if ($lat !== null && $lng !== null) {
            return [$lat, $lng, 'app'];
        }

        // La sesión tiene la última posición aunque el ping no se haya guardado por estar detenido
        $session = CollectorSession::where('collector_id', $collectorId)
            ->where('last_seen_at', '>=', now()->subMinutes(self::DEVICE_LOCATION_MAX_AGE_MIN))
            ->whereNotNull('current_latitude')
            ->latest('last_seen_at')
            ->first();

        return $session
            ? [$session->current_latitude, $session->current_longitude, 'device']
            : [null, null, null];
    }

    public function runDistance(RouteRun $run): float
    {
        $points = $run->pings()
            ->where(fn ($q) => $q->whereNull('accuracy')->orWhere('accuracy', '<=', self::MAX_PROGRESS_ACCURACY_M))
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude']);

        return $this->pathDistance($points->map(fn ($p) => ['lat' => $p->latitude, 'lng' => $p->longitude])->all());
    }

    public function pathDistance(array $points): float
    {
        $total = 0.0;

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $total += $this->simplifier->haversineMeters(
                $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                $points[$i]['lat'], $points[$i]['lng']
            );
        }

        return $total;
    }
}
