<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Models\Schedule;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    use ApiResponse;

    private const DAY_RULE = 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday';

    /**
     * @OA\Get(
     *     path="/api/admin/routes/{route}/schedules",
     *     tags={"Schedules"},
     *     summary="Horario semanal de una ruta",
     *     description="Retorna los 7 dias. Los dias configurados tienen horas; los no configurados aparecen con null.",
     *     operationId="schedulesIndex",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="route", type="object",
     *                     @OA\Property(property="id",   type="integer", example=1),
     *                     @OA\Property(property="name", type="string",  example="Ruta Sur #1"),
     *                     @OA\Property(property="zone", type="string",  nullable=true)
     *                 ),
     *                 @OA\Property(property="schedule", type="array", @OA\Items(
     *                     @OA\Property(property="day",          type="string",  example="monday"),
     *                     @OA\Property(property="day_name",     type="string",  example="Lunes"),
     *                     @OA\Property(property="route_id",     type="integer", example=1),
     *                     @OA\Property(property="schedule_id",  type="integer", nullable=true, example=3),
     *                     @OA\Property(property="start_time",   type="string",  nullable=true, example="07:00"),
     *                     @OA\Property(property="end_time",     type="string",  nullable=true, example="09:00"),
     *                     @OA\Property(property="active",       type="boolean", example=true),
     *                     @OA\Property(property="configured",   type="boolean", example=true)
     *                 ))
     *             )
     *         )
     *     )
     * )
     *
     * Get the full weekly schedule for a route.
     * Returns all 7 days — configured ones have times, unconfigured appear as null.
     */
    public function index(Route $route): JsonResponse
    {
        $configured = $route->schedules()->get()->keyBy('day_of_week');

        $week = collect(Schedule::DAYS)->map(function (string $day) use ($configured, $route) {
            $s = $configured->get($day);
            return [
                'day'        => $day,
                'day_name'   => Schedule::DAYS_ES[$day],
                'route_id'   => $route->id,
                'schedule_id'=> $s?->id,
                'start_time' => $s?->start_time,
                'end_time'   => $s?->end_time,
                'active'     => $s ? $s->active : false,
                'configured' => $s !== null,
            ];
        });

        return $this->success([
            'route'    => ['id' => $route->id, 'name' => $route->name, 'zone' => $route->zone],
            'schedule' => $week,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/routes/{route}/schedules",
     *     tags={"Schedules"},
     *     summary="Configurar un dia del horario",
     *     description="Agrega o actualiza un dia de la semana para esta ruta (upsert por dia).",
     *     operationId="schedulesStore",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"day_of_week","start_time"},
     *             @OA\Property(property="day_of_week", type="string", example="monday",
     *                 enum={"monday","tuesday","wednesday","thursday","friday","saturday","sunday"}
     *             ),
     *             @OA\Property(property="start_time", type="string", example="07:00"),
     *             @OA\Property(property="end_time",   type="string", example="09:00", nullable=true),
     *             @OA\Property(property="active",     type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Horario configurado",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Schedule")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     *
     * Add or update a single day for this route.
     */
    public function store(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate([
            'day_of_week' => ['required', self::DAY_RULE],
            'start_time'  => 'required|date_format:H:i',
            'end_time'    => 'nullable|date_format:H:i|after:start_time',
            'active'      => 'boolean',
        ]);

        $schedule = Schedule::updateOrCreate(
            ['route_id' => $route->id, 'day_of_week' => $data['day_of_week']],
            [
                'start_time' => $data['start_time'],
                'end_time'   => $data['end_time'] ?? null,
                'active'     => $data['active'] ?? true,
            ]
        );

        return $this->created($this->formatSchedule($schedule), 'Horario configurado');
    }

    /**
     * @OA\Put(
     *     path="/api/admin/routes/{route}/schedules",
     *     tags={"Schedules"},
     *     summary="Configurar multiples dias (mantiene los no incluidos)",
     *     description="Upsert de varios dias a la vez. Los dias no incluidos en el payload se conservan.",
     *     operationId="schedulesBulkStore",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"days"},
     *             @OA\Property(property="days", type="array", minItems=1, maxItems=7,
     *                 @OA\Items(
     *                     required={"day_of_week","start_time"},
     *                     @OA\Property(property="day_of_week", type="string", example="monday",
     *                         enum={"monday","tuesday","wednesday","thursday","friday","saturday","sunday"}
     *                     ),
     *                     @OA\Property(property="start_time", type="string", example="07:00"),
     *                     @OA\Property(property="end_time",   type="string", example="09:00", nullable=true),
     *                     @OA\Property(property="active",     type="boolean", example=true)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="route",            type="object"),
     *                 @OA\Property(property="days_configured",  type="integer", example=3),
     *                 @OA\Property(property="schedules",        type="array", @OA\Items(ref="#/components/schemas/Schedule"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     *
     * Configure the entire week for a route in one request.
     * Days not included are left untouched (use DELETE to remove them).
     */
    public function bulkStore(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate([
            'days'                => 'required|array|min:1|max:7',
            'days.*.day_of_week'  => ['required', self::DAY_RULE],
            'days.*.start_time'   => 'required|date_format:H:i',
            'days.*.end_time'     => 'nullable|date_format:H:i',
            'days.*.active'       => 'boolean',
        ]);

        // Validate no duplicate days in the payload
        $submitted = array_column($data['days'], 'day_of_week');
        if (count($submitted) !== count(array_unique($submitted))) {
            return $this->error('El payload contiene días duplicados', null, 422);
        }

        $saved = collect($data['days'])->map(function (array $day) use ($route) {
            $schedule = Schedule::updateOrCreate(
                ['route_id' => $route->id, 'day_of_week' => $day['day_of_week']],
                [
                    'start_time' => $day['start_time'],
                    'end_time'   => $day['end_time'] ?? null,
                    'active'     => $day['active'] ?? true,
                ]
            );
            return $this->formatSchedule($schedule);
        });

        return $this->success([
            'route'          => ['id' => $route->id, 'name' => $route->name],
            'days_configured' => $saved->count(),
            'schedules'       => $saved,
        ], 'Horarios configurados');
    }

    /**
     * @OA\Put(
     *     path="/api/admin/routes/{route}/schedules/{schedule}",
     *     tags={"Schedules"},
     *     summary="Actualizar un dia especifico",
     *     operationId="schedulesUpdate",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route",    in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="schedule", in="path", required=true, @OA\Schema(type="integer", example=3)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="start_time", type="string",  example="08:00"),
     *             @OA\Property(property="end_time",   type="string",  example="10:00", nullable=true),
     *             @OA\Property(property="active",     type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", ref="#/components/schemas/Schedule")
     *         )
     *     ),
     *     @OA\Response(response=404, description="No encontrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     *
     * Update a specific day's schedule.
     */
    public function update(Request $request, Route $route, Schedule $schedule): JsonResponse
    {
        abort_if($schedule->route_id !== $route->id, 404);

        $data = $request->validate([
            'start_time' => 'sometimes|date_format:H:i',
            'end_time'   => 'nullable|date_format:H:i|after:start_time',
            'active'     => 'boolean',
        ]);

        $schedule->update($data);

        return $this->success($this->formatSchedule($schedule->fresh()), 'Horario actualizado');
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/routes/{route}/schedules/{schedule}",
     *     tags={"Schedules"},
     *     summary="Eliminar un dia del horario",
     *     operationId="schedulesDestroy",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route",    in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="schedule", in="path", required=true, @OA\Schema(type="integer", example=3)),
     *     @OA\Response(response=200, description="Eliminado", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="No encontrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     *
     * Remove a day from a route's schedule.
     */
    public function destroy(Route $route, Schedule $schedule): JsonResponse
    {
        abort_if($schedule->route_id !== $route->id, 404);

        $schedule->delete();

        return $this->success(null, 'Día eliminado del horario');
    }

    /**
     * @OA\Patch(
     *     path="/api/admin/routes/{route}/schedules",
     *     tags={"Schedules"},
     *     summary="Reemplazar horario completo",
     *     description="Sustituye TODOS los dias de la semana. Los dias no incluidos en el payload son eliminados.",
     *     operationId="schedulesReplaceAll",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="route", in="path", required=true, @OA\Schema(type="integer", example=1)),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"days"},
     *             @OA\Property(property="days", type="array", minItems=1, maxItems=7,
     *                 @OA\Items(
     *                     required={"day_of_week","start_time"},
     *                     @OA\Property(property="day_of_week", type="string", example="monday",
     *                         enum={"monday","tuesday","wednesday","thursday","friday","saturday","sunday"}
     *                     ),
     *                     @OA\Property(property="start_time", type="string", example="07:00"),
     *                     @OA\Property(property="end_time",   type="string", example="09:00", nullable=true),
     *                     @OA\Property(property="active",     type="boolean", example=true)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(allOf={@OA\Schema(ref="#/components/schemas/ApiResponse")},
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="route",           type="object"),
     *                 @OA\Property(property="days_configured", type="integer", example=3),
     *                 @OA\Property(property="schedules",       type="array", @OA\Items(ref="#/components/schemas/Schedule"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Error de validacion", @OA\JsonContent(ref="#/components/schemas/ValidationError"))
     * )
     *
     * Replace ALL days of a route's schedule at once.
     * Any existing days not in the payload are deleted.
     */
    public function replaceAll(Request $request, Route $route): JsonResponse
    {
        $data = $request->validate([
            'days'               => 'required|array|min:1|max:7',
            'days.*.day_of_week' => ['required', self::DAY_RULE],
            'days.*.start_time'  => 'required|date_format:H:i',
            'days.*.end_time'    => 'nullable|date_format:H:i|after:days.*.start_time',
            'days.*.active'      => 'boolean',
        ]);

        $submitted = array_column($data['days'], 'day_of_week');
        if (count($submitted) !== count(array_unique($submitted))) {
            return $this->error('El payload contiene días duplicados', null, 422);
        }

        // Delete days not present in the new payload
        $route->schedules()->whereNotIn('day_of_week', $submitted)->delete();

        $saved = collect($data['days'])->map(function (array $day) use ($route) {
            $schedule = Schedule::updateOrCreate(
                ['route_id' => $route->id, 'day_of_week' => $day['day_of_week']],
                [
                    'start_time' => $day['start_time'],
                    'end_time'   => $day['end_time'] ?? null,
                    'active'     => $day['active'] ?? true,
                ]
            );
            return $this->formatSchedule($schedule);
        });

        return $this->success([
            'route'           => ['id' => $route->id, 'name' => $route->name],
            'days_configured' => $saved->count(),
            'schedules'       => $saved,
        ], 'Horario semanal reemplazado');
    }

    private function formatSchedule(Schedule $s): array
    {
        return [
            'id'          => $s->id,
            'route_id'    => $s->route_id,
            'day_of_week' => $s->day_of_week,
            'day_name'    => Schedule::DAYS_ES[$s->day_of_week],
            'start_time'  => $s->start_time,
            'end_time'    => $s->end_time,
            'active'      => $s->active,
        ];
    }
}
