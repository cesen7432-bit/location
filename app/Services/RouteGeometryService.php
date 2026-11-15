<?php

namespace App\Services;

use App\Models\LocationRouteMatch;
use App\Models\Route;
use App\Models\UserLocation;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class RouteGeometryService
{
    private const EARTH_RADIUS = 6371000;

    public const MATCH_MAX_DISTANCE_M = 150;
    public const OFF_ROUTE_DISTANCE_M = 50;
    public const OFF_ROUTE_PINGS      = 3;

    public const MAX_ACCURACY_M = 30;
    public const MAX_SPEED_KMH  = 120;
    public const SIMPLIFY_EPS_M = 5.0;

    private static array $memo = [];

    public function __construct(private readonly RouteSimplificationService $simplifier) {}

    public static function forget(int $routeId): void
    {
        unset(self::$memo[$routeId]);
        Cache::forget(self::cacheKey($routeId));
    }

    private static function cacheKey(int $routeId): string
    {
        return "route-geometry:v1:{$routeId}";
    }

    public function prepared(Route|int $route): ?array
    {
        $routeId = $route instanceof Route ? $route->id : $route;

        if (array_key_exists($routeId, self::$memo)) {
            return self::$memo[$routeId];
        }

        $prepared = Cache::rememberForever(self::cacheKey($routeId), function () use ($route, $routeId) {
            $model = $route instanceof Route ? $route : Route::find($routeId);

            // rememberForever no guarda null: "sin trazado" se guarda como false
            return $model && $model->hasGeometry()
                ? $this->prepare($this->geoJsonToLatLng($model->geometry))
                : false;
        });

        return self::$memo[$routeId] = $prepared ?: null;
    }

    /**
     * @param  array  $latLng  [[lat, lng], ...]
     */
    public function prepare(array $latLng): array
    {
        if (count($latLng) < 2) {
            throw new InvalidArgumentException('La ruta necesita al menos 2 puntos.');
        }

        $lat0 = $latLng[0][0];
        $lng0 = $latLng[0][1];
        $kx   = deg2rad(1) * self::EARTH_RADIUS * cos(deg2rad($lat0));
        $ky   = deg2rad(1) * self::EARTH_RADIUS;

        $xy  = [];
        $cum = [0.0];

        foreach ($latLng as $i => [$lat, $lng]) {
            $xy[$i] = [($lng - $lng0) * $kx, ($lat - $lat0) * $ky];

            if ($i > 0) {
                $cum[$i] = $cum[$i - 1] + hypot($xy[$i][0] - $xy[$i - 1][0], $xy[$i][1] - $xy[$i - 1][1]);
            }
        }

        return [
            'lat0'   => $lat0,
            'lng0'   => $lng0,
            'kx'     => $kx,
            'ky'     => $ky,
            'xy'     => $xy,
            'cum'    => $cum,
            'length' => end($cum),
            'count'  => count($xy),
        ];
    }

    /**
     * Con $hintSegment (tramo del ping anterior) solo se revisa una ventana de ~35 tramos;
     * la línea completa se recorre solo si ahí no hay nada cerca (primer ping, desvío, salto del GPS).
     *
     * @return array{distance_m: float, along_m: float, segment_index: int, progress: float}
     */
    public function project(array $prepared, float $lat, float $lng, ?int $hintSegment = null): array
    {
        $px = ($lng - $prepared['lng0']) * $prepared['kx'];
        $py = ($lat - $prepared['lat0']) * $prepared['ky'];
        $segments = $prepared['count'] - 1;

        $best = null;

        if ($hintSegment !== null && $hintSegment >= 0 && $hintSegment < $segments) {
            $best = $this->scan($prepared, $px, $py, max(0, $hintSegment - 5), min($segments - 1, $hintSegment + 30));

            if ($best['distance_m'] > self::OFF_ROUTE_DISTANCE_M) {
                $best = null;
            }
        }

        $best ??= $this->scan($prepared, $px, $py, 0, $segments - 1);

        $best['progress'] = $prepared['length'] > 0
            ? round(min(100, $best['along_m'] / $prepared['length'] * 100), 2)
            : 0.0;

        return $best;
    }

    private function scan(array $p, float $px, float $py, int $from, int $to): array
    {
        $bestDist  = INF;
        $bestAlong = 0.0;
        $bestIndex = $from;

        for ($i = $from; $i <= $to; $i++) {
            [$ax, $ay] = $p['xy'][$i];
            [$bx, $by] = $p['xy'][$i + 1];

            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;

            $t = $len2 > 0 ? (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2 : 0.0;
            $t = max(0.0, min(1.0, $t));

            $dist = hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));

            if ($dist < $bestDist) {
                $bestDist  = $dist;
                $bestIndex = $i;
                $bestAlong = $p['cum'][$i] + $t * sqrt($len2);
            }
        }

        return [
            'distance_m'    => round($bestDist, 1),
            'along_m'       => round($bestAlong, 1),
            'segment_index' => $bestIndex,
        ];
    }

    /**
     * @param  iterable  $pings  con latitude, longitude, accuracy y recorded_at
     * @return array  [[lat, lng], ...]
     */
    public function lineFromPings(iterable $pings): array
    {
        $points = [];

        foreach ($pings as $p) {
            $points[] = [
                'lat'      => (float) $p->latitude,
                'lng'      => (float) $p->longitude,
                'accuracy' => $p->accuracy,
                'time'     => $p->recorded_at,
            ];
        }

        return $this->simplifyLatLng($this->cleanPoints($points));
    }

    /**
     * Descarta puntos imprecisos, repetidos y saltos imposibles. Sin 'time' no se evalúa la velocidad.
     *
     * @param  array  $points  [['lat', 'lng', 'accuracy' => ?float, 'time' => ?Carbon], ...] en orden
     * @return array  [[lat, lng], ...]
     */
    public function cleanPoints(array $points): array
    {
        $kept = [];
        $last = null;

        foreach ($points as $point) {
            if (($point['accuracy'] ?? null) !== null && $point['accuracy'] > self::MAX_ACCURACY_M) {
                continue;
            }

            if ($last !== null) {
                $meters = $this->simplifier->haversineMeters($last['lat'], $last['lng'], $point['lat'], $point['lng']);

                if ($meters < 1) {
                    continue;
                }

                if (!empty($point['time']) && !empty($last['time'])) {
                    $seconds = max(1, abs($point['time']->getTimestamp() - $last['time']->getTimestamp()));

                    if (($meters / $seconds) * 3.6 > self::MAX_SPEED_KMH) {
                        continue;
                    }
                }
            }

            $kept[] = $point;
            $last   = $point;
        }

        return array_map(fn ($p) => [$p['lat'], $p['lng']], $kept);
    }

    public function simplifyLatLng(array $latLng, float $epsilon = self::SIMPLIFY_EPS_M): array
    {
        if (count($latLng) < 3) {
            return $latLng;
        }

        $points = array_map(fn ($p) => ['lat' => $p[0], 'lng' => $p[1]], $latLng);

        return array_map(
            fn ($p) => [round($p['lat'], 7), round($p['lng'], 7)],
            $this->simplifier->simplify($points, $epsilon)
        );
    }

    public function saveGeometry(Route $route, array $latLng, string $source): Route
    {
        if (count($latLng) < 2) {
            throw new InvalidArgumentException('La ruta necesita al menos 2 puntos válidos.');
        }

        $prepared = $this->prepare($latLng);

        $route->update([
            'geometry' => [
                'type'        => 'LineString',
                'coordinates' => array_map(fn ($p) => [round($p[1], 7), round($p[0], 7)], $latLng),
            ],
            'geometry_source' => $source,
            'distance_m'      => (int) round($prepared['length']),
        ]);

        self::forget($route->id);
        $this->rematchRoute($route);

        return $route;
    }

    public function clearGeometry(Route $route): void
    {
        $route->update(['geometry' => null, 'geometry_source' => null, 'distance_m' => null]);
        self::forget($route->id);
        $route->locationMatches()->delete();
    }

    /** GeoJSON guarda [lng, lat]; internamente se usa [lat, lng]. */
    public function geoJsonToLatLng(array $geometry): array
    {
        return array_map(fn ($c) => [(float) $c[1], (float) $c[0]], $geometry['coordinates'] ?? []);
    }

    public function summary(Route $route): array
    {
        $path = $this->path($route);

        return [
            'route'            => ['id' => $route->id, 'name' => $route->name, 'code' => $route->code],
            'source'           => $route->geometry_source,
            'distance_m'       => $route->distance_m,
            'points_count'     => count($path),
            'served_locations' => $route->locationMatches()->count(),
            'updated_at'       => $route->updated_at?->toIso8601String(),
            'path'             => $path,
        ];
    }

    public function path(Route $route): array
    {
        return $route->hasGeometry()
            ? array_map(fn ($p) => ['lat' => $p[0], 'lng' => $p[1]], $this->geoJsonToLatLng($route->geometry))
            : [];
    }

    public function parseDocument(string $content, ?string $extension = null): array
    {
        $content   = trim($content);
        $extension = strtolower((string) $extension);

        if ($extension === 'gpx' || $extension === 'kml' || str_starts_with($content, '<')) {
            return $this->parseXml($content);
        }

        $json = json_decode($content, true);

        if (!is_array($json)) {
            throw new InvalidArgumentException('Archivo no reconocido: use GPX, KML o GeoJSON.');
        }

        return $this->parseGeoJson($json);
    }

    /** Si hay varias líneas se concatenan en orden. */
    public function parseGeoJson(array $json): array
    {
        $lines = [];

        $collect = function (?array $geometry) use (&$lines) {
            $type = $geometry['type'] ?? null;

            if ($type === 'LineString') {
                $lines[] = $geometry['coordinates'] ?? [];
            } elseif ($type === 'MultiLineString') {
                foreach ($geometry['coordinates'] ?? [] as $line) {
                    $lines[] = $line;
                }
            }
        };

        switch ($json['type'] ?? null) {
            case 'FeatureCollection':
                foreach ($json['features'] ?? [] as $feature) {
                    $collect($feature['geometry'] ?? null);
                }
                break;
            case 'Feature':
                $collect($json['geometry'] ?? null);
                break;
            default:
                $collect($json);
        }

        $latLng = [];
        foreach ($lines as $line) {
            foreach ((array) $line as $c) {
                if (is_array($c) && isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1])) {
                    $latLng[] = [(float) $c[1], (float) $c[0]];
                }
            }
        }

        return $this->validateLatLng($latLng);
    }

    private function parseXml(string $content): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new InvalidArgumentException('El archivo XML no es válido.');
        }

        $latLng = [];

        // GPX
        foreach ($xml->xpath("//*[local-name()='trkpt' or local-name()='rtept']") ?: [] as $pt) {
            $latLng[] = [(float) $pt['lat'], (float) $pt['lon']];
        }

        if (!$latLng) {
            // KML: "lng,lat[,alt]" separados por espacios
            foreach ($xml->xpath("//*[local-name()='LineString']/*[local-name()='coordinates']") ?: [] as $coords) {
                foreach (preg_split('/\s+/', trim((string) $coords)) as $tuple) {
                    $parts = explode(',', $tuple);
                    if (count($parts) >= 2) {
                        $latLng[] = [(float) $parts[1], (float) $parts[0]];
                    }
                }
            }

            // KML gx:Track: "lng lat alt"
            foreach ($xml->xpath("//*[local-name()='coord']") ?: [] as $coord) {
                $parts = preg_split('/\s+/', trim((string) $coord));
                if (count($parts) >= 2) {
                    $latLng[] = [(float) $parts[1], (float) $parts[0]];
                }
            }
        }

        return $this->validateLatLng($latLng);
    }

    private function validateLatLng(array $latLng): array
    {
        $valid = array_values(array_filter(
            $latLng,
            fn ($p) => $p[0] >= -90 && $p[0] <= 90 && $p[1] >= -180 && $p[1] <= 180 && !($p[0] == 0 && $p[1] == 0)
        ));

        if (count($valid) < 2) {
            throw new InvalidArgumentException('No se encontró una línea con al menos 2 puntos válidos.');
        }

        return $valid;
    }

    public function matchLocation(UserLocation $location): void
    {
        $keep = [];

        Route::query()
            ->where('active', true)
            ->whereNotNull('geometry')
            ->select('id', 'geometry', 'active')
            ->each(function (Route $route) use ($location, &$keep) {
                $prepared = $this->prepared($route);

                if (!$prepared) {
                    return;
                }

                $hit = $this->project($prepared, $location->latitude, $location->longitude);

                if ($hit['distance_m'] <= self::MATCH_MAX_DISTANCE_M) {
                    $this->upsertMatch($location->id, $route->id, $hit);
                    $keep[] = $route->id;
                }
            });

        $location->routeMatches()->whereNotIn('route_id', $keep ?: [0])->delete();
    }

    /** Solo se revisan las ubicaciones dentro del rectángulo de la ruta más el margen. */
    public function rematchRoute(Route $route): void
    {
        $prepared = $this->prepared($route);

        if (!$prepared || !$route->active) {
            $route->locationMatches()->delete();
            return;
        }

        $latLng = $this->geoJsonToLatLng($route->geometry);
        $lats   = array_column($latLng, 0);
        $lngs   = array_column($latLng, 1);
        $dLat   = self::MATCH_MAX_DISTANCE_M / 111320;
        $dLng   = $dLat / max(0.01, cos(deg2rad($latLng[0][0])));

        $keep = [];

        UserLocation::query()
            ->whereBetween('latitude', [min($lats) - $dLat, max($lats) + $dLat])
            ->whereBetween('longitude', [min($lngs) - $dLng, max($lngs) + $dLng])
            ->select('id', 'latitude', 'longitude')
            ->chunkById(500, function ($locations) use ($prepared, $route, &$keep) {
                foreach ($locations as $location) {
                    $hit = $this->project($prepared, $location->latitude, $location->longitude);

                    if ($hit['distance_m'] <= self::MATCH_MAX_DISTANCE_M) {
                        $this->upsertMatch($location->id, $route->id, $hit);
                        $keep[] = $location->id;
                    }
                }
            });

        $route->locationMatches()->whereNotIn('user_location_id', $keep ?: [0])->delete();
    }

    private function upsertMatch(int $locationId, int $routeId, array $hit): void
    {
        LocationRouteMatch::updateOrCreate(
            ['user_location_id' => $locationId, 'route_id' => $routeId],
            ['along_m' => (int) round($hit['along_m']), 'distance_m' => $hit['distance_m']]
        );
    }
}
