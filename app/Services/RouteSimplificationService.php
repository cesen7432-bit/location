<?php

namespace App\Services;

class RouteSimplificationService
{
    // Earth radius in meters
    private const EARTH_RADIUS = 6371000;

    /**
     * Simplify a raw breadcrumb trail using Ramer-Douglas-Peucker.
     *
     * @param  array  $points  [['lat' => float, 'lng' => float, ...], ...]
     * @param  float  $epsilonMeters  Max deviation allowed (default 15m)
     * @return array  Simplified points (same structure, subset of input)
     */
    public function simplify(array $points, float $epsilonMeters = 15.0): array
    {
        if (count($points) < 3) {
            return $points;
        }

        return $this->rdp($points, $epsilonMeters);
    }

    /**
     * Calculate the straight-line distance in meters between two GPS coords.
     */
    public function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS * asin(sqrt($a));
    }

    /**
     * Check if a collector has moved enough to warrant a WebSocket broadcast.
     *
     * @param  float  $distanceThresholdMeters  Broadcast if moved more than this
     * @param  int    $timeThresholdSeconds      OR if this many seconds have passed
     */
    public function shouldBroadcast(
        float $newLat,
        float $newLng,
        ?float $lastLat,
        ?float $lastLng,
        ?\DateTimeInterface $lastBroadcastAt,
        float $distanceThresholdMeters = 15.0,
        int $timeThresholdSeconds = 10
    ): bool {
        if ($lastLat === null || $lastLng === null) {
            return true;
        }

        $distanceMoved = $this->haversineMeters($lastLat, $lastLng, $newLat, $newLng);

        if ($distanceMoved >= $distanceThresholdMeters) {
            return true;
        }

        if ($lastBroadcastAt === null) {
            return true;
        }

        $secondsSinceLastBroadcast = now()->diffInSeconds($lastBroadcastAt);

        return $secondsSinceLastBroadcast >= $timeThresholdSeconds && $distanceMoved > 0;
    }

    /**
     * Ramer-Douglas-Peucker recursive simplification.
     */
    private function rdp(array $points, float $epsilon): array
    {
        $n = count($points);

        if ($n < 3) {
            return $points;
        }

        $maxDist  = 0.0;
        $maxIndex = 0;
        $first    = $points[0];
        $last     = $points[$n - 1];

        for ($i = 1; $i < $n - 1; $i++) {
            $dist = $this->perpendicularDistanceMeters($points[$i], $first, $last);

            if ($dist > $maxDist) {
                $maxDist  = $dist;
                $maxIndex = $i;
            }
        }

        if ($maxDist > $epsilon) {
            $left  = $this->rdp(array_slice($points, 0, $maxIndex + 1), $epsilon);
            $right = $this->rdp(array_slice($points, $maxIndex), $epsilon);

            // Merge without duplicating the split point
            return array_merge(array_slice($left, 0, -1), $right);
        }

        return [$first, $last];
    }

    /**
     * Perpendicular distance from point P to the line segment (A, B) in meters.
     * Uses the cross-track distance formula for GPS coords.
     */
    private function perpendicularDistanceMeters(array $p, array $a, array $b): float
    {
        $distAB = $this->haversineMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']);

        // Degenerate segment: A and B are the same point
        if ($distAB < 1e-10) {
            return $this->haversineMeters($a['lat'], $a['lng'], $p['lat'], $p['lng']);
        }

        $distAP = $this->haversineMeters($a['lat'], $a['lng'], $p['lat'], $p['lng']);

        // Bearing A→B and A→P
        $bearingAB = $this->bearing($a['lat'], $a['lng'], $b['lat'], $b['lng']);
        $bearingAP = $this->bearing($a['lat'], $a['lng'], $p['lat'], $p['lng']);

        // Cross-track distance (signed)
        $angularDist = $distAP / self::EARTH_RADIUS;
        $deltaAngle  = deg2rad($bearingAP - $bearingAB);

        return abs(asin(sin($angularDist) * sin($deltaAngle)) * self::EARTH_RADIUS);
    }

    private function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $dLng = deg2rad($lng2 - $lng1);

        $y = sin($dLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);

        return rad2deg(atan2($y, $x));
    }
}
