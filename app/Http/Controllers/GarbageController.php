<?php

namespace App\Http\Controllers;

use App\Models\UserLocation;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GarbageController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(path="/api/collector/garbage-points", tags={"Collector"}, summary="Puntos con basura afuera",
     *     description="Ubicaciones donde el usuario marcó que sacó la basura (últimas 24 h). Sin datos personales: solo coordenadas. Con route_id se limita a las ubicaciones de esa ruta.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     * @OA\Get(path="/api/admin/garbage-points", tags={"Tracking"}, summary="Puntos con basura afuera (admin)",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function points(Request $request): JsonResponse
    {
        $request->validate(['route_id' => 'nullable|integer|exists:routes,id']);

        $points = UserLocation::query()
            ->where('garbage_out', true)
            ->where('garbage_out_at', '>=', now()->subHours(UserLocation::GARBAGE_OUT_TTL_HOURS))
            ->when($request->filled('route_id'), fn ($q) => $q->whereHas(
                'routeMatches',
                fn ($m) => $m->where('route_id', $request->input('route_id'))
            ))
            ->with('routeMatches:id,user_location_id,route_id')
            ->orderBy('garbage_out_at')
            ->get()
            ->map(fn (UserLocation $l) => [
                'id'        => $l->id,
                'latitude'  => $l->latitude,
                'longitude' => $l->longitude,
                'out_at'    => $l->garbage_out_at?->toIso8601String(),
                'route_ids' => $l->routeMatches->pluck('route_id')->values(),
            ]);

        return $this->success([
            'count'  => $points->count(),
            'points' => $points,
        ]);
    }
}
