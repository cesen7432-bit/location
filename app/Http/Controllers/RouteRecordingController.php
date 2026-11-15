<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Models\RouteRecording;
use App\Services\RouteGeometryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RouteRecordingController extends Controller
{
    use ApiResponse;

    private const MAX_POINTS_PER_REQUEST = 1000;

    public function __construct(private readonly RouteGeometryService $geometry) {}

    /**
     * @OA\Post(path="/api/admin/routes/{route}/recordings", tags={"Routes"}, summary="Iniciar grabación de trazado",
     *     description="Abre una grabación para enviar los puntos del GPS del navegador por tramos. Si ya hay una grabación abierta para la ruta responde 409 con ella (para retomarla).",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(@OA\Property(property="notes", type="string", nullable=true))),
     *     @OA\Response(response=201, description="Grabación iniciada", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Ya hay una grabación abierta", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function start(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);

        $open = RouteRecording::where('route_id', $route->id)->where('status', RouteRecording::RECORDING)->first();

        if ($open) {
            return $this->error('Ya hay una grabación abierta para esta ruta', $open->load('route')->toApi(), 409);
        }

        $recording = RouteRecording::create([
            'route_id'   => $route->id,
            'user_id'    => $request->user()->id,
            'status'     => RouteRecording::RECORDING,
            'started_at' => now(),
            'notes'      => $data['notes'] ?? null,
        ]);

        return $this->created($recording->load('route')->toApi(), 'Grabación iniciada');
    }

    /**
     * @OA\Post(path="/api/admin/recordings/{recording}/points", tags={"Routes"}, summary="Enviar tramo de puntos",
     *     description="Hasta 1000 puntos por petición. Reenviar un tramo es seguro: los puntos con el mismo recorded_at no se duplican.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="recording", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"points"},
     *         @OA\Property(property="points", type="array", @OA\Items(type="object",
     *             @OA\Property(property="lat", type="number", example=-3.99312),
     *             @OA\Property(property="lng", type="number", example=-79.20441),
     *             @OA\Property(property="accuracy", type="number", example=6.5),
     *             @OA\Property(property="recorded_at", type="string", format="date-time", example="2026-11-09T13:00:05.120Z"))))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="La grabación no está abierta", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function addPoints(Request $request, RouteRecording $recording): JsonResponse
    {
        $request->validate([
            'points'               => 'required|array|min:1|max:' . self::MAX_POINTS_PER_REQUEST,
            'points.*.lat'         => 'required|numeric|between:-90,90',
            'points.*.lng'         => 'required|numeric|between:-180,180',
            'points.*.accuracy'    => 'nullable|numeric|min:0',
            'points.*.recorded_at' => 'required|date',
        ]);

        if (!$recording->isRecording()) {
            return $this->error('La grabación ya no está abierta', $recording->toApi(), 409);
        }

        $tz   = config('app.timezone');
        $rows = array_map(fn ($p) => [
            'route_recording_id' => $recording->id,
            'latitude'           => (float) $p['lat'],
            'longitude'          => (float) $p['lng'],
            'accuracy'           => isset($p['accuracy']) ? (float) $p['accuracy'] : null,
            'recorded_at'        => Carbon::parse($p['recorded_at'])->setTimezone($tz)->format('Y-m-d H:i:s.v'),
        ], $request->input('points'));

        $stored = DB::table('route_recording_points')->insertOrIgnore($rows);

        $recording->update(['point_count' => $recording->points()->count()]);

        return $this->success([
            'received'    => count($rows),
            'stored'      => $stored,
            'point_count' => $recording->point_count,
        ], 'Puntos recibidos');
    }

    /**
     * @OA\Get(path="/api/admin/recordings/{recording}", tags={"Routes"}, summary="Ver grabación",
     *     description="Estado y vista previa de la línea limpia y simplificada con los puntos recibidos hasta ahora.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="recording", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function show(RouteRecording $recording): JsonResponse
    {
        $line = $this->line($recording);

        return $this->success([
            'recording' => $recording->load('route')->toApi(),
            'preview'   => [
                'distance_m'   => count($line) >= 2 ? (int) round($this->geometry->prepare($line)['length']) : 0,
                'points_count' => count($line),
                'path'         => array_map(fn ($p) => ['lat' => $p[0], 'lng' => $p[1]], $line),
            ],
        ]);
    }

    /**
     * @OA\Post(path="/api/admin/recordings/{recording}/finish", tags={"Routes"}, summary="Finalizar grabación y guardar trazado",
     *     description="Limpia los puntos (precisión > 30 m, repetidos, saltos > 120 km/h), simplifica y guarda la línea como trazado de la ruta.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="recording", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Trazado guardado", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="La grabación no está abierta", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Puntos insuficientes", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function finish(RouteRecording $recording): JsonResponse
    {
        if (!$recording->isRecording()) {
            return $this->error('La grabación ya no está abierta', $recording->toApi(), 409);
        }

        try {
            $this->geometry->saveGeometry($recording->route, $this->line($recording), 'recording');
        } catch (InvalidArgumentException) {
            // La grabación sigue abierta para poder enviar más puntos
            return $this->error('No hay puntos válidos suficientes para crear el trazado', $recording->toApi(), 422);
        }

        $recording->update(['status' => RouteRecording::FINISHED, 'finished_at' => now()]);

        return $this->success([
            'recording' => $recording->load('route')->toApi(),
            'geometry'  => $this->geometry->summary($recording->route->fresh()),
        ], 'Trazado guardado desde la grabación');
    }

    /**
     * @OA\Delete(path="/api/admin/recordings/{recording}", tags={"Routes"}, summary="Cancelar grabación",
     *     description="Descarta la grabación y sus puntos. El trazado actual de la ruta no cambia.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="recording", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function cancel(RouteRecording $recording): JsonResponse
    {
        if (!$recording->isRecording()) {
            return $this->error('La grabación ya no está abierta', $recording->toApi(), 409);
        }

        DB::table('route_recording_points')->where('route_recording_id', $recording->id)->delete();
        $recording->update(['status' => RouteRecording::CANCELLED, 'finished_at' => now(), 'point_count' => 0]);

        return $this->success($recording->load('route')->toApi(), 'Grabación cancelada');
    }

    private function line(RouteRecording $recording): array
    {
        $points = $recording->points()->get()->map(fn ($p) => [
            'lat'      => (float) $p->latitude,
            'lng'      => (float) $p->longitude,
            'accuracy' => $p->accuracy !== null ? (float) $p->accuracy : null,
            'time'     => Carbon::parse($p->recorded_at),
        ])->all();

        return $this->geometry->simplifyLatLng($this->geometry->cleanPoints($points));
    }
}
