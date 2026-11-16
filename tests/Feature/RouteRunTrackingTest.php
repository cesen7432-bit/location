<?php

namespace Tests\Feature;

use App\Models\CollectorSession;
use App\Models\CollectorTracking;
use App\Models\LocationRouteMatch;
use App\Models\PushSubscription;
use App\Models\RouteRun;
use App\Models\UserLocation;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Concerns\BuildsTrackingData;
use Tests\TestCase;

class RouteRunTrackingTest extends TestCase
{
    use RefreshDatabase, BuildsTrackingData;

    private $collector;
    private string $collectorToken;
    private string $apiKey;
    private string $adminToken;
    private $route;
    private $resident;
    private UserLocation $home;
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();

        $this->pushes = [];
        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendBatch')->andReturnUsing(function (array $items) {
            array_push($this->pushes, ...$items);
            return count($items);
        });
        $push->shouldReceive('isConfigured')->andReturnTrue();
        $this->app->instance(WebPushService::class, $push);

        $this->collector      = $this->makeUser('collector');
        $this->collectorToken = $this->tokenFor($this->collector);
        $this->apiKey         = $this->apiKeyFor($this->collector);
        $this->adminToken     = $this->tokenFor($this->makeUser('admin'));
        $this->route          = $this->makeRouteWithGeometry();
        $this->assignToday($this->collector, $this->route);

        $this->resident = $this->makeUser('user');
        $token = $this->tokenFor($this->resident);

        // Casa a 11 m al este del metro 600 de la ruta
        $res = $this->as($token, 'POST', '/api/me/locations', [
            'label'     => 'Casa',
            'latitude'  => $this->latAt(600),
            'longitude' => self::LNG + 0.0001,
        ])->assertCreated();

        $this->home = UserLocation::findOrFail($res->json('result.id'));

        PushSubscription::create([
            'user_id'       => $this->resident->id,
            'endpoint'      => 'https://push.example.com/abc',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example.com/abc'),
            'public_key'    => 'pk',
            'auth_token'    => 'auth',
        ]);
    }

    public function test_location_is_matched_to_the_route(): void
    {
        $match = LocationRouteMatch::where('user_location_id', $this->home->id)->first();

        $this->assertNotNull($match);
        $this->assertEquals($this->route->id, $match->route_id);
        $this->assertEqualsWithDelta(600, $match->along_m, 2);
        $this->assertEqualsWithDelta(11, $match->distance_m, 2);
    }

    public function test_only_routes_assigned_today_can_be_started(): void
    {
        $other = $this->makeRouteWithGeometry('Ruta 9');

        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $other->id])
            ->assertForbidden()
            ->assertJsonPath('code', -1);

        \App\Models\CollectorRoute::create([
            'collector_id' => $this->collector->id, 'route_id' => $other->id,
            'assigned_date' => today()->subDay()->toDateString(), 'active' => true,
        ]);

        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $other->id])
            ->assertForbidden();

        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])
            ->assertCreated();
    }

    public function test_ping_without_run_is_stored_as_idle(): void
    {
        $this->ping($this->apiKey, 0)
            ->assertOk()
            ->assertJsonPath('result.run_id', null)
            ->assertJsonPath('result.route_id', null);

        $session = CollectorSession::where('collector_id', $this->collector->id)->first();
        $this->assertSame('idle', $session->status);
        $this->assertNull($session->route_id);
    }

    public function test_stationary_pings_are_not_stored(): void
    {
        $this->ping($this->apiKey, 0)->assertOk()->assertJsonPath('result.stored', true);
        $this->ping($this->apiKey, 1)->assertOk()->assertJsonPath('result.stored', false);
        $this->ping($this->apiKey, 1.5)->assertOk()->assertJsonPath('result.stored', false);
        $this->ping($this->apiKey, 5)->assertOk()->assertJsonPath('result.stored', true);

        $this->assertSame(2, CollectorTracking::where('collector_id', $this->collector->id)->count());

        // La sesión sí sigue la última posición
        $session = CollectorSession::where('collector_id', $this->collector->id)->first();
        $this->assertEqualsWithDelta($this->latAt(5), $session->current_latitude, 1e-6);

        // Al iniciar un recorrido el primer ping se guarda aunque el camión no se mueva
        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])->assertCreated();
        $this->ping($this->apiKey, 5)->assertOk()->assertJsonPath('result.stored', true);
    }

    public function test_full_run_flow(): void
    {
        $this->ping($this->apiKey, -50)->assertOk();

        // Inicio sin coordenadas: usa el último ping del dispositivo
        $start = $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])
            ->assertCreated()
            ->assertJsonPath('result.status', 'in_progress')
            ->assertJsonPath('result.start.source', 'device');
        $runId = $start->json('result.id');

        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])
            ->assertStatus(409)
            ->assertJsonPath('code', -1);

        $this->ping($this->apiKey, 10)->assertOk()->assertJsonPath('result.run_id', $runId);

        // Metro 200: la casa (metro 600) queda a 400 m, dentro de los 500 m por defecto
        $this->ping($this->apiKey, 200)->assertOk();
        $this->assertCount(1, $this->resident->fresh()->notifications);
        $this->assertCount(1, $this->pushes);
        $this->assertSame('collector_nearby', $this->pushes[0][1]['data']['type']);

        // No se repite en el mismo recorrido
        $this->ping($this->apiKey, 300)->assertOk();
        $this->assertCount(1, $this->resident->fresh()->notifications);

        $res = $this->ping($this->apiKey, 400)->assertOk();
        $this->assertEqualsWithDelta(40, $res->json('result.progress'), 0.5);

        $this->as($this->collectorToken, 'POST', '/api/collector/runs/finish', [
            'latitude' => $this->latAt(1000), 'longitude' => self::LNG,
        ])
            ->assertOk()
            ->assertJsonPath('result.status', 'completed')
            ->assertJsonPath('result.end.source', 'app')
            ->assertJsonPath('result.closed_by', 'collector')
            ->assertJsonPath('result.ping_count', 4);

        $run = RouteRun::find($runId);
        $this->assertEqualsWithDelta(390, $run->distance_m, 5);
        $this->assertEqualsWithDelta(40, $run->max_progress, 0.5);

        $session = CollectorSession::where('collector_id', $this->collector->id)->first();
        $this->assertSame('idle', $session->status);
        $this->assertNull($session->run_id);
        $this->assertNull($session->progress);

        $this->ping($this->apiKey, 1100)->assertOk()->assertJsonPath('result.run_id', null);

        $this->as($this->collectorToken, 'POST', '/api/collector/runs/finish')->assertNotFound();

        // Reporte del día: idle → run → idle
        $day = $this->as($this->adminToken, 'GET', "/api/admin/collectors/{$this->collector->id}/day-track?date=" . today()->toDateString())
            ->assertOk();
        $this->assertSame(['idle', 'run', 'idle'], array_column($day->json('result.segments'), 'type'));
        $this->assertSame(1, $day->json('result.summary.runs_count'));
        $this->assertNotNull($day->json('result.runs.0.start.latitude'));
        $this->assertNotNull($day->json('result.runs.0.end.latitude'));

        $this->as($this->adminToken, 'GET', '/api/admin/runs?date=' . today()->toDateString())
            ->assertOk()
            ->assertJsonPath('result.pagination.total', 1)
            ->assertJsonPath('result.items.0.id', $runId);

        $detail = $this->as($this->adminToken, 'GET', "/api/admin/runs/{$runId}")->assertOk();
        $this->assertCount(3, $detail->json('result.planned_path'));
        $this->assertNotEmpty($detail->json('result.actual_path'));
    }

    public function test_notifications_respect_switches(): void
    {
        $this->resident->update(['notifications_enabled' => false]);

        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])->assertCreated();
        $this->ping($this->apiKey, 0);
        $this->ping($this->apiKey, 200);

        $this->assertCount(0, $this->resident->fresh()->notifications);

        $this->resident->update(['notifications_enabled' => true]);
        $this->home->update(['notify_enabled' => false]);
        $this->ping($this->apiKey, 250);
        $this->assertCount(0, $this->resident->fresh()->notifications);

        // Con distancia de 100 m solo avisa al llegar al metro 500
        $this->home->update(['notify_enabled' => true, 'notify_distance_m' => 100]);
        $this->ping($this->apiKey, 300);
        $this->assertCount(0, $this->resident->fresh()->notifications);
        $this->ping($this->apiKey, 510);
        $this->assertCount(1, $this->resident->fresh()->notifications);
    }

    public function test_off_route_after_three_pings(): void
    {
        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])->assertCreated();

        $this->ping($this->apiKey, 100)->assertJsonPath('result.off_route', false);
        $this->ping($this->apiKey, 120, 200)->assertJsonPath('result.off_route', false);
        $this->ping($this->apiKey, 140, 200)->assertJsonPath('result.off_route', false);
        $res = $this->ping($this->apiKey, 160, 200)->assertJsonPath('result.off_route', true);

        // Fuera de ruta no avanza
        $this->assertEqualsWithDelta(10, $res->json('result.progress'), 0.5);

        $this->ping($this->apiKey, 180)->assertJsonPath('result.off_route', false);
    }

    public function test_garbage_is_cleared_when_the_truck_passes(): void
    {
        $token = $this->tokenFor($this->resident);

        $this->as($token, 'PATCH', "/api/me/locations/{$this->home->id}/garbage", ['out' => true])
            ->assertOk()
            ->assertJsonPath('result.garbage.out', true);

        $this->as($this->collectorToken, 'GET', '/api/collector/garbage-points?route_id=' . $this->route->id)
            ->assertOk()
            ->assertJsonPath('result.count', 1)
            ->assertJsonMissingPath('result.points.0.label');

        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])->assertCreated();
        $this->ping($this->apiKey, 500);
        $this->assertTrue($this->home->fresh()->garbage_out);

        $this->ping($this->apiKey, 610);
        $this->assertFalse($this->home->fresh()->garbage_out);
        $this->assertNotNull($this->home->fresh()->garbage_collected_at);

        $this->as($this->adminToken, 'GET', '/api/admin/garbage-points')
            ->assertOk()
            ->assertJsonPath('result.count', 0);
    }

    public function test_admin_closes_forgotten_run_with_last_ping(): void
    {
        $runId = $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])
            ->json('result.id');
        $this->ping($this->apiKey, 100);
        $this->travel(10)->seconds();
        $this->ping($this->apiKey, 300);

        $res = $this->as($this->adminToken, 'POST', "/api/admin/runs/{$runId}/finish")
            ->assertOk()
            ->assertJsonPath('result.closed_by', 'admin')
            ->assertJsonPath('result.end.source', 'device');
        $this->assertEqualsWithDelta($this->latAt(300), $res->json('result.end.latitude'), 1e-6);

        $this->as($this->adminToken, 'POST', "/api/admin/runs/{$runId}/finish")->assertStatus(409);
    }

    public function test_geometry_from_recorded_run(): void
    {
        $newRoute = \App\Models\Route::create(['name' => 'Ruta nueva', 'active' => true]);
        $this->assignToday($this->collector, $newRoute);

        $runId = $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $newRoute->id])
            ->json('result.id');

        foreach ([0, 30, 60, 90, 120] as $i => $m) {
            $this->travel(5)->seconds();
            $this->ping($this->apiKey, $m);
        }
        // Ping impreciso: se descarta
        $this->travel(5)->seconds();
        $this->ping($this->apiKey, 150, 300, ['accuracy' => 80]);

        $res = $this->as($this->adminToken, 'POST', "/api/admin/routes/{$newRoute->id}/geometry/from-tracking", ['run_id' => $runId])
            ->assertOk()
            ->assertJsonPath('result.source', 'tracking')
            ->assertJsonPath('result.raw_ping_count', 6);

        $this->assertCount(2, $res->json('result.path'));
        $this->assertEqualsWithDelta(120, $res->json('result.distance_m'), 2);

        $this->getJson("/api/public/routes/{$newRoute->id}/geometry")->assertOk()->assertJsonPath('result.points_count', 2);
    }

    public function test_geometry_upload_gpx_and_geojson(): void
    {
        $gpx = '<?xml version="1.0"?><gpx xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>'
            . '<trkpt lat="-4.0" lon="-79.19"/><trkpt lat="-3.995" lon="-79.19"/><trkpt lat="-3.995" lon="-79.185"/>'
            . '</trkseg></trk></gpx>';

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('ruta.gpx', $gpx);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => "Bearer {$this->adminToken}", 'Accept' => 'application/json'])
            ->put("/api/admin/routes/{$this->route->id}/geometry", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('result.points_count', 3)
            ->assertJsonPath('result.source', 'import');

        // La casa queda a >150 m de la nueva línea: deja de estar servida
        $this->assertSame(0, LocationRouteMatch::where('user_location_id', $this->home->id)->count());

        $this->as($this->adminToken, 'PUT', "/api/admin/routes/{$this->route->id}/geometry", [
            'geometry' => ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[-79.2, -4.0], [-79.2, -3.99]]]],
        ])->assertOk()->assertJsonPath('result.points_count', 2);

        $this->assertSame(1, LocationRouteMatch::where('user_location_id', $this->home->id)->count());

        $this->as($this->adminToken, 'PUT', "/api/admin/routes/{$this->route->id}/geometry", [
            'geometry' => ['type' => 'Point', 'coordinates' => [-79.2, -4.0]],
        ])->assertStatus(422)->assertJsonPath('code', -1);
    }
}
