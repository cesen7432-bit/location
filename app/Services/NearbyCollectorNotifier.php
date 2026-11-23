<?php

namespace App\Services;

use App\Models\LocationRouteMatch;
use App\Models\PushSubscription;
use App\Models\RouteRun;
use App\Models\Schedule;
use App\Models\User;
use App\Models\UserLocation;
use App\Notifications\CollectorNearbyNotification;

class NearbyCollectorNotifier
{
    private const MAX_WINDOW_M    = UserLocation::MAX_NOTIFY_DISTANCE;
    private const PASSED_WINDOW_M = 300;
    private const PASSED_AHEAD_M  = 20;

    public function __construct(private readonly WebPushService $push) {}

    public function notifyApproaching(RouteRun $run, User $collector, float $truckAlong): int
    {
        $truckAlong = (int) round($truckAlong);
        $routeName  = $run->route?->name ?? 'Ruta';

        $candidates = LocationRouteMatch::query()
            ->join('user_locations as l', 'l.id', '=', 'location_route_matches.user_location_id')
            ->join('users as u', 'u.id', '=', 'l.user_id')
            ->where('location_route_matches.route_id', $run->route_id)
            ->whereBetween('location_route_matches.along_m', [$truckAlong, $truckAlong + self::MAX_WINDOW_M])
            // Sin restas: along_m es UNSIGNED y MySQL fallaría con un resultado negativo
            ->whereRaw('location_route_matches.along_m <= ? + l.notify_distance_m', [$truckAlong])
            ->where(fn ($q) => $q
                ->whereNull('location_route_matches.last_notified_run_id')
                ->orWhere('location_route_matches.last_notified_run_id', '!=', $run->id))
            ->where('l.notify_enabled', true)
            ->where('u.notifications_enabled', true)
            ->where('u.active', true)
            ->select([
                'location_route_matches.id',
                'location_route_matches.along_m',
                'l.id as location_id',
                'l.label as location_label',
                'l.user_id',
            ])
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $wasteType = Schedule::where('route_id', $run->route_id)
            ->where('day_of_week', strtolower(now()->englishDayOfWeek))
            ->value('waste_type');
        $wasteText = $wasteType ? ' Hoy: ' . Schedule::WASTE_TYPES_ES[$wasteType] . '.' : '';

        $users = User::whereIn('id', $candidates->pluck('user_id')->unique())->get()->keyBy('id');
        $subscriptions = PushSubscription::whereIn('user_id', $users->keys())->get()->groupBy('user_id');

        $pushes   = [];
        $notified = 0;

        foreach ($candidates as $match) {
            // Update condicional: si dos pings llegan a la vez, solo uno notifica
            $claimed = LocationRouteMatch::whereKey($match->id)
                ->where(fn ($q) => $q->whereNull('last_notified_run_id')->orWhere('last_notified_run_id', '!=', $run->id))
                ->update(['last_notified_run_id' => $run->id, 'last_notified_at' => now()]);

            if (!$claimed) {
                continue;
            }

            $distance = max(0, $match->along_m - $truckAlong);
            $payload  = [
                'type'           => 'collector_nearby',
                'title'          => 'El recolector está cerca',
                'body'           => "{$routeName}: el camión está a unos {$this->roundDistance($distance)} m de {$match->location_label}.{$wasteText}",
                'route_id'       => $run->route_id,
                'route_name'     => $routeName,
                'run_id'         => $run->id,
                'collector_id'   => $collector->id,
                'location_id'    => $match->location_id,
                'location_label' => $match->location_label,
                'distance_m'     => $distance,
                'waste_type'     => $wasteType,
            ];

            $users[$match->user_id]->notify(new CollectorNearbyNotification($payload));

            foreach ($subscriptions[$match->user_id] ?? [] as $subscription) {
                $pushes[] = [$subscription, [
                    'title' => $payload['title'],
                    'body'  => $payload['body'],
                    'tag'   => "collector-nearby-{$match->location_id}",
                    'data'  => $payload,
                ]];
            }

            $notified++;
        }

        $this->push->sendBatch($pushes);

        return $notified;
    }

    /** Limpia "basura afuera" de las ubicaciones por las que el camión pasó entre dos pings. */
    public function markCollected(RouteRun $run, ?float $previousAlong, float $truckAlong): int
    {
        $to   = (int) round($truckAlong) + self::PASSED_AHEAD_M;
        $from = $previousAlong !== null && $previousAlong < $truckAlong
            ? max((int) round($previousAlong), $to - self::PASSED_WINDOW_M)
            : $to - self::PASSED_WINDOW_M;

        $locationIds = LocationRouteMatch::query()
            ->where('route_id', $run->route_id)
            ->whereBetween('along_m', [$from, $to])
            ->pluck('user_location_id');

        if ($locationIds->isEmpty()) {
            return 0;
        }

        return UserLocation::whereIn('id', $locationIds)
            ->where('garbage_out', true)
            ->update([
                'garbage_out'          => false,
                'garbage_collected_at' => now(),
                'updated_at'           => now(),
            ]);
    }

    private function roundDistance(int $meters): int
    {
        return $meters < 100 ? max(10, (int) (round($meters / 10) * 10)) : (int) (round($meters / 50) * 50);
    }
}
