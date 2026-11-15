<?php

namespace App\Http\Controllers;

use App\Models\CollectorTracking;
use App\Models\Route;
use App\Models\RouteRun;
use App\Services\RouteGeometryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class RouteGeometryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RouteGeometryService $geometry) {}

    /**
     * @OA\Get(path="/api/admin/routes/{route}/geometry", tags={"Routes"}, summary="Trazado de la ruta",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     * @OA\Get(path="/api/public/routes/{route}/geometry", tags={"Public"}, summary="Trazado de la ruta (público)",
     *     description="Línea de la ruta como [{lat, lng}] en orden de recorrido, para dibujarla en el mapa.",
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Ruta no disponible", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function show(Request $request, Route $route): JsonResponse
    {
        if ($request->is('api/public/*') && !$route->active) {
            return $this->notFound('Ruta no disponible');
        }

        return $this->success($this->resource($route));
    }

    /**
     * @OA\Put(path="/api/admin/routes/{route}/geometry", tags={"Routes"}, summary="Cargar trazado (archivo o JSON)",
     *     description="Acepta multipart 'file' (.gpx, .kml, .geojson, .json), o JSON con 'geometry' (GeoJSON) o 'path' ([{lat,lng}]). simplify=true (por defecto) reduce puntos a ~5 m de tolerancia.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true,
     *         @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(
     *             @OA\Property(property="file", type="string", format="binary"),
     *             @OA\Property(property="simplify", type="boolean"))),
     *         @OA\JsonContent(
     *             @OA\Property(property="geometry", type="object", example={"type":"LineString","coordinates":{{-79.2044,-3.9931},{-79.2043,-3.9928}}}),
     *             @OA\Property(property="path", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="lat", type="number"), @OA\Property(property="lng", type="number"))),
     *             @OA\Property(property="simplify", type="boolean", example=true))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Archivo o línea inválida", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function store(Request $request, Route $route): JsonResponse
    {
        $request->validate(RouteGeometryService::inputRules(true));

        try {
            [$latLng, $source] = $this->geometry->lineFromInput($request);
            $this->geometry->saveGeometry($route, $latLng, $source);

            if ($request->boolean('detect_neighborhoods')) {
                $this->geometry->detectNeighborhoods($route);
            }
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success($this->resource($route->fresh()), 'Trazado guardado');
    }

    /**
     * @OA\Post(path="/api/admin/routes/{route}/geometry/from-tracking", tags={"Routes"}, summary="Crear trazado desde lo grabado por el GPS",
     *     description="Con run_id usa los pings de ese recorrido. Si no, collector_id + date (y opcionalmente from/to en HH:MM). Descarta pings imprecisos (> 30 m) y saltos imposibles, y simplifica.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="run_id", type="integer", example=12),
     *         @OA\Property(property="collector_id", type="integer", example=4),
     *         @OA\Property(property="date", type="string", format="date", example="2026-11-15"),
     *         @OA\Property(property="from", type="string", example="07:00"),
     *         @OA\Property(property="to", type="string", example="11:30"))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Sin pings suficientes", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function fromTracking(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate([
            'run_id'       => 'required_without:collector_id|nullable|integer|exists:route_runs,id',
            'collector_id' => 'required_without:run_id|nullable|integer|exists:users,id',
            'date'         => 'required_with:collector_id|nullable|date',
            'from'         => 'nullable|date_format:H:i',
            'to'           => 'nullable|date_format:H:i',
        ]);

        if (!empty($data['run_id'])) {
            $run   = RouteRun::findOrFail($data['run_id']);
            $query = $run->pings();
        } else {
            $date  = Carbon::parse($data['date']);
            $query = CollectorTracking::where('collector_id', $data['collector_id'])
                ->where('tracking_date', $date->toDateString())
                ->when($data['from'] ?? null, fn ($q, $from) => $q->where('recorded_at', '>=', $date->copy()->setTimeFromTimeString($from)))
                ->when($data['to'] ?? null, fn ($q, $to) => $q->where('recorded_at', '<=', $date->copy()->setTimeFromTimeString($to)->endOfMinute()));
        }

        $pings  = $query->orderBy('recorded_at')->get(['latitude', 'longitude', 'accuracy', 'recorded_at']);
        $latLng = $this->geometry->lineFromPings($pings);

        if (count($latLng) < 2) {
            return $this->error('No hay pings suficientes para crear el trazado', ['ping_count' => $pings->count()], 422);
        }

        $this->geometry->saveGeometry($route, $latLng, 'tracking');

        $result = $this->resource($route->fresh());
        $result['raw_ping_count'] = $pings->count();

        return $this->success($result, 'Trazado creado desde el recorrido grabado');
    }

    /**
     * @OA\Delete(path="/api/admin/routes/{route}/geometry", tags={"Routes"}, summary="Eliminar trazado",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function destroy(Route $route): JsonResponse
    {
        $this->geometry->clearGeometry($route);

        return $this->success(null, 'Trazado eliminado');
    }

    private function resource(Route $route): array
    {
        return $this->geometry->summary($route);
    }
}
