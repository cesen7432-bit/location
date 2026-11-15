<?php

namespace Tests\Feature;

use App\Models\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\BuildsTrackingData;
use Tests\TestCase;

class RouteRecordingTest extends TestCase
{
    use RefreshDatabase, BuildsTrackingData;

    private string $admin;
    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->tokenFor($this->makeUser('admin'));
        $this->route = Route::create(['name' => 'Ruta 5', 'active' => true])->refresh();
    }

    /** Puntos cada 5 s avanzando 20 m hacia el norte. */
    private function points(int $from, int $count, \Carbon\CarbonInterface $start): array
    {
        $out = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $out[] = [
                'lat'         => $this->latAt($i * 20),
                'lng'         => self::LNG,
                'accuracy'    => 5,
                'recorded_at' => $start->copy()->addSeconds($i * 5)->toIso8601ZuluString('millisecond'),
            ];
        }
        return $out;
    }

    public function test_chunked_recording_flow(): void
    {
        $start = Carbon::parse('2026-11-09T13:00:00Z');

        $id = $this->as($this->admin, 'POST', "/api/admin/routes/{$this->route->id}/recordings")
            ->assertCreated()
            ->assertJsonPath('result.status', 'recording')
            ->json('result.id');

        // Una segunda grabación de la misma ruta devuelve la abierta para retomarla
        $this->as($this->admin, 'POST', "/api/admin/routes/{$this->route->id}/recordings")
            ->assertStatus(409)
            ->assertJsonPath('result.id', $id);

        $first = $this->points(0, 10, $start);
        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/points", ['points' => $first])
            ->assertOk()->assertJsonPath('result.stored', 10)->assertJsonPath('result.point_count', 10);

        // Reenvío del mismo tramo (corte de red): no duplica
        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/points", ['points' => $first])
            ->assertOk()->assertJsonPath('result.stored', 0)->assertJsonPath('result.point_count', 10);

        $second = $this->points(10, 10, $start);
        $second[3]['accuracy'] = 80;
        $second[5]['lng'] = self::LNG + 0.02; // salto de ~2 km en 5 s
        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/points", ['points' => $second])
            ->assertOk()->assertJsonPath('result.point_count', 20);

        $preview = $this->as($this->admin, 'GET', "/api/admin/recordings/{$id}")->assertOk();
        $this->assertCount(2, $preview->json('result.preview.path'));
        $this->assertEqualsWithDelta(380, $preview->json('result.preview.distance_m'), 2);

        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/finish")
            ->assertOk()
            ->assertJsonPath('result.recording.status', 'finished')
            ->assertJsonPath('result.geometry.source', 'recording')
            ->assertJsonPath('result.geometry.points_count', 2);

        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/points", ['points' => $first])->assertStatus(409);
        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/finish")->assertStatus(409);

        $this->assertTrue($this->route->fresh()->hasGeometry());
    }

    public function test_recording_creates_a_route_that_can_be_assigned_and_started(): void
    {
        $near = \App\Models\Neighborhood::create(['name' => 'Jipiro', 'latitude' => $this->latAt(300), 'longitude' => self::LNG + 0.001, 'active' => true]);
        $first = \App\Models\Neighborhood::create(['name' => 'Las Palmas', 'latitude' => $this->latAt(20), 'longitude' => self::LNG, 'active' => true]);
        \App\Models\Neighborhood::create(['name' => 'Lejano', 'latitude' => $this->latAt(300), 'longitude' => self::LNG + 0.01, 'active' => true]);

        $id = $this->as($this->admin, 'POST', '/api/admin/recordings', ['notes' => 'Ruta nueva'])
            ->assertCreated()
            ->assertJsonPath('result.route', null)
            ->json('result.id');

        // Varias grabaciones de rutas nuevas pueden estar abiertas a la vez
        $this->as($this->admin, 'POST', '/api/admin/recordings')->assertCreated();

        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/points", ['points' => $this->points(0, 25, now())])->assertOk();

        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/finish")
            ->assertStatus(422)
            ->assertJsonValidationErrors('name', 'result');

        $res = $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/finish", [
            'name' => 'Ruta 4 - Sector Este', 'code' => '4', 'shift' => 'day', 'zone' => 'Este',
        ])->assertOk()
            ->assertJsonPath('result.route_created', true)
            ->assertJsonPath('result.route.code', '4')
            ->assertJsonPath('result.route.neighborhoods.0.id', $first->id)
            ->assertJsonPath('result.route.neighborhoods.1.id', $near->id)
            ->assertJsonCount(2, 'result.route.neighborhoods')
            ->assertJsonPath('result.geometry.source', 'recording');

        $routeId = $res->json('result.route.id');
        $this->as($this->admin, 'GET', '/api/admin/recordings?status=recording')->assertOk()->assertJsonCount(1, 'result');

        $collector = $this->makeUser('collector');
        $this->as($this->admin, 'POST', '/api/admin/collector/assign-route', [
            'collector_id' => $collector->id, 'route_id' => $routeId, 'assigned_date' => today()->toDateString(),
        ])->assertCreated();

        $this->as($this->tokenFor($collector), 'POST', '/api/collector/runs/start', ['route_id' => $routeId])->assertCreated();
    }

    public function test_route_can_be_created_with_its_geometry(): void
    {
        $nb = \App\Models\Neighborhood::create(['name' => 'Centro', 'latitude' => $this->latAt(100), 'longitude' => self::LNG, 'active' => true]);

        $this->as($this->admin, 'POST', '/api/admin/routes', [
            'name' => 'Ruta 6', 'code' => '6',
            'path' => $this->points(0, 10, now()),
        ])->assertCreated()
            ->assertJsonPath('result.has_geometry', true)
            ->assertJsonPath('result.points.0.neighborhood.id', $nb->id);

        $this->as($this->admin, 'POST', '/api/admin/routes', [
            'name' => 'Ruta inválida', 'geometry' => ['type' => 'Point', 'coordinates' => [-79.2, -4.0]],
        ])->assertStatus(422);

        $this->assertNull(Route::where('name', 'Ruta inválida')->first());
    }

    public function test_finish_without_enough_points_keeps_recording_open(): void
    {
        $id = $this->as($this->admin, 'POST', "/api/admin/routes/{$this->route->id}/recordings")->json('result.id');

        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/points", ['points' => $this->points(0, 1, now())]);

        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/finish")
            ->assertStatus(422)
            ->assertJsonPath('result.status', 'recording');
    }

    public function test_cancel_recording(): void
    {
        $id = $this->as($this->admin, 'POST', "/api/admin/routes/{$this->route->id}/recordings")->json('result.id');
        $this->as($this->admin, 'POST', "/api/admin/recordings/{$id}/points", ['points' => $this->points(0, 5, now())]);

        $this->as($this->admin, 'DELETE', "/api/admin/recordings/{$id}")
            ->assertOk()
            ->assertJsonPath('result.status', 'cancelled')
            ->assertJsonPath('result.point_count', 0);

        $this->assertFalse($this->route->fresh()->hasGeometry());

        // Se puede abrir una nueva
        $this->as($this->admin, 'POST', "/api/admin/routes/{$this->route->id}/recordings")->assertCreated();
    }

    public function test_path_with_accuracy_and_time_is_cleaned(): void
    {
        $points = $this->points(0, 10, Carbon::parse('2026-11-09T13:00:00Z'));
        $points[4]['accuracy'] = 90;
        $points[6]['lng'] = self::LNG + 0.02;

        $this->as($this->admin, 'PUT', "/api/admin/routes/{$this->route->id}/geometry", ['path' => $points])
            ->assertOk()
            ->assertJsonPath('result.source', 'recording')
            ->assertJsonPath('result.points_count', 2);

        // Multipart desde el navegador: POST con _method=PUT
        $gpx = '<gpx><trk><trkseg><trkpt lat="-4.0" lon="-79.2"/><trkpt lat="-3.99" lon="-79.2"/></trkseg></trk></gpx>';
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => "Bearer {$this->admin}", 'Accept' => 'application/json'])
            ->post("/api/admin/routes/{$this->route->id}/geometry", [
                '_method' => 'PUT',
                'file'    => \Illuminate\Http\UploadedFile::fake()->createWithContent('ruta.gpx', $gpx),
            ])
            ->assertOk()
            ->assertJsonPath('result.source', 'import');

        // Sin recorded_at sigue siendo un trazado dibujado
        $this->as($this->admin, 'PUT', "/api/admin/routes/{$this->route->id}/geometry", [
            'path' => [['lat' => -4.0, 'lng' => -79.2], ['lat' => -3.99, 'lng' => -79.2]],
        ])->assertOk()->assertJsonPath('result.source', 'manual');
    }
}
