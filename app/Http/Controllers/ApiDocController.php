<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     title="API Recolección de Residuos",
 *     version="1.0.0",
 *     description="API para la gestión de rutas de recolección, seguimiento en tiempo real de recolectores y consulta de horarios por barrio.",
 *     @OA\Contact(email="admin@example.com")
 * )
 *
 * @OA\Server(url="/", description="Servidor local")
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Token Sanctum. Obtenerlo en POST /api/auth/login"
 * )
 *
 * @OA\Tag(name="Auth",          description="Autenticación y sesión")
 * @OA\Tag(name="Public",        description="Endpoints sin autenticación — horarios y tracking live")
 * @OA\Tag(name="Collector",     description="Endpoints para recolectores autenticados")
 * @OA\Tag(name="Neighborhoods", description="Catálogo de barrios (admin)")
 * @OA\Tag(name="Routes",        description="Gestión de rutas GPS (admin)")
 * @OA\Tag(name="Schedules",     description="Horarios semanales por ruta (admin)")
 * @OA\Tag(name="Tracking",      description="Reportes y seguimiento de recolectores (admin)")
 *
 * -----------------------------------------------------------------------
 * Reusable Schemas
 * -----------------------------------------------------------------------
 *
 * @OA\Schema(
 *     schema="ApiResponse",
 *     @OA\Property(property="success", type="boolean", example=true),
 *     @OA\Property(property="message", type="string",  example="OK"),
 *     @OA\Property(property="data",    nullable=true),
 *     @OA\Property(property="errors",  nullable=true),
 *     @OA\Property(property="code",    type="integer", example=200)
 * )
 *
 * @OA\Schema(
 *     schema="Neighborhood",
 *     @OA\Property(property="id",           type="integer", example=1),
 *     @OA\Property(property="name",         type="string",  example="El Centro"),
 *     @OA\Property(property="municipality", type="string",  example="Bogotá", nullable=true),
 *     @OA\Property(property="zone",         type="string",  example="Sur",    nullable=true),
 *     @OA\Property(property="latitude",     type="number",  format="float",   example=4.5981),
 *     @OA\Property(property="longitude",    type="number",  format="float",   example=-74.0758),
 *     @OA\Property(property="notes",        type="string",  nullable=true),
 *     @OA\Property(property="active",       type="boolean", example=true)
 * )
 *
 * @OA\Schema(
 *     schema="Schedule",
 *     @OA\Property(property="id",          type="integer", example=1),
 *     @OA\Property(property="route_id",    type="integer", example=1),
 *     @OA\Property(property="day_of_week", type="string",  example="monday",
 *         enum={"monday","tuesday","wednesday","thursday","friday","saturday","sunday"}),
 *     @OA\Property(property="day_name",    type="string",  example="Lunes"),
 *     @OA\Property(property="start_time",  type="string",  example="07:00"),
 *     @OA\Property(property="end_time",    type="string",  example="09:00", nullable=true),
 *     @OA\Property(property="active",      type="boolean", example=true)
 * )
 *
 * @OA\Schema(
 *     schema="RoutePoint",
 *     @OA\Property(property="sequence",     type="integer", example=1),
 *     @OA\Property(property="notes",        type="string",  nullable=true),
 *     @OA\Property(property="neighborhood", ref="#/components/schemas/Neighborhood")
 * )
 *
 * @OA\Schema(
 *     schema="Route",
 *     @OA\Property(property="id",           type="integer", example=1),
 *     @OA\Property(property="name",         type="string",  example="Ruta Sur #1"),
 *     @OA\Property(property="description",  type="string",  nullable=true),
 *     @OA\Property(property="zone",         type="string",  example="Sur", nullable=true),
 *     @OA\Property(property="active",       type="boolean", example=true),
 *     @OA\Property(property="total_points", type="integer", example=5),
 *     @OA\Property(property="schedule",     type="array", @OA\Items(ref="#/components/schemas/Schedule")),
 *     @OA\Property(property="points",       type="array", @OA\Items(ref="#/components/schemas/RoutePoint"))
 * )
 *
 * @OA\Schema(
 *     schema="CollectorSession",
 *     @OA\Property(property="collector_id",   type="integer", example=3),
 *     @OA\Property(property="collector_name", type="string",  example="Juan Pérez"),
 *     @OA\Property(property="route",          type="object",
 *         @OA\Property(property="id",   type="integer", example=1),
 *         @OA\Property(property="name", type="string",  example="Ruta Sur #1"),
 *         @OA\Property(property="zone", type="string",  example="Sur")
 *     ),
 *     @OA\Property(property="latitude",     type="number", format="float", example=4.5981),
 *     @OA\Property(property="longitude",    type="number", format="float", example=-74.0758),
 *     @OA\Property(property="status",       type="string", example="on_route",
 *         enum={"on_route","paused","completed"}),
 *     @OA\Property(property="last_seen_at", type="string", format="date-time", example="2026-05-01T08:30:00+00:00")
 * )
 *
 * @OA\Schema(
 *     schema="ValidationError",
 *     @OA\Property(property="success", type="boolean", example=false),
 *     @OA\Property(property="message", type="string",  example="Error de validación"),
 *     @OA\Property(property="data",    nullable=true,  example=null),
 *     @OA\Property(property="errors",  type="object",
 *         @OA\Property(property="field", type="array", @OA\Items(type="string", example="El campo es obligatorio."))
 *     ),
 *     @OA\Property(property="code", type="integer", example=422)
 * )
 */
class ApiDocController {}
