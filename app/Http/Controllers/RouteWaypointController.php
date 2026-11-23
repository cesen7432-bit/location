<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Services\RouteWaypointService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RouteWaypointController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RouteWaypointService $waypoints) {}

    /**
     * @OA\Get(path="/api/admin/routes/{route}/waypoints", tags={"Routes"}, summary="Puntos especiales del trazado",
     *     description="Inicio, pausas, relleno sanitario, reanudaciones y fin, ordenados por sequence. landfill_trips = cantidad de puntos de relleno.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Route $route): JsonResponse
    {
        return $this->success($this->waypoints->summary($route));
    }

    /**
     * @OA\Put(path="/api/admin/routes/{route}/waypoints", tags={"Routes"}, summary="Reemplazar puntos especiales",
     *     description="Reemplaza la lista completa; una lista vacía borra todos. El primero debe ser start; máximo un start y un end (el end va al final); resume va después de pause o landfill.",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"waypoints"},
     *         @OA\Property(property="waypoints", type="array", maxItems=50, @OA\Items(
     *             required={"type","lat","lng"},
     *             @OA\Property(property="type", type="string", enum={"start","pause","landfill","resume","end"}),
     *             @OA\Property(property="lat", type="number", example=-3.99),
     *             @OA\Property(property="lng", type="number", example=-79.2),
     *             @OA\Property(property="sequence", type="integer", nullable=true, example=1),
     *             @OA\Property(property="notes", type="string", nullable=true)
     *         ))
     *     )),
     *     @OA\Response(response=200, description="Puntos especiales guardados", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Orden inválido", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function update(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate([
            'waypoints'            => 'present|array|max:50',
            'waypoints.*.type'     => 'required|in:start,pause,landfill,resume,end',
            'waypoints.*.lat'      => 'required|numeric|between:-90,90',
            'waypoints.*.lng'      => 'required|numeric|between:-180,180',
            'waypoints.*.sequence' => 'nullable|integer|min:1',
            'waypoints.*.notes'    => 'nullable|string|max:255',
        ]);

        try {
            $this->waypoints->replace($route, $data['waypoints']);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success($this->waypoints->summary($route), 'Puntos especiales guardados');
    }
}
