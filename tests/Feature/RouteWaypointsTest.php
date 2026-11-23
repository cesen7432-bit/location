<?php

namespace Tests\Feature;

use App\Models\RouteRun;
use App\Models\Schedule;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Concerns\BuildsTrackingData;
use Tests\TestCase;

class RouteWaypointsTest extends TestCase
{
    use RefreshDatabase, BuildsTrackingData;

    private $collector;
    private string $collectorToken;
    private string $apiKey;
    private string $adminToken;
    private $route;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();

        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendBatch')->andReturn(0);
        $push->shouldReceive('isConfigured')->andReturnTrue();
        $this->app->instance(WebPushService::class, $push);

        $this->collector      = $this->makeUser('collector');
        $this->collectorToken = $this->tokenFor($this->collector);
        $this->apiKey         = $this->apiKeyFor($this->collector);
        $this->adminToken     = $this->tokenFor($this->makeUser('admin'));
        $this->route          = $this->makeRouteWithGeometry();
        $this->assignToday($this->collector, $this->route);
    }

    private function lngAt(float $eastMeters): float
    {
        return self::LNG + $eastMeters / (self::M_PER_DEG * cos(deg2rad(self::START_LAT)));
    }

    private function saveWaypoints(array $waypoints)
    {
        return $this->as($this->adminToken, 'PUT', "/api/admin/routes/{$this->route->id}/waypoints", ['waypoints' => $waypoints]);
    }

    private function fullTrip(): array
    {
        return [
            ['type' => 'start', 'lat' => $this->latAt(0), 'lng' => self::LNG],
            ['type' => 'pause', 'lat' => $this->latAt(400), 'lng' => self::LNG],
            ['type' => 'landfill', 'lat' => $this->latAt(400), 'lng' => $this->lngAt(2000), 'notes' => 'Relleno'],
            ['type' => 'resume', 'lat' => $this->latAt(400), 'lng' => self::LNG],
            ['type' => 'end', 'lat' => $this->latAt(1000), 'lng' => self::LNG],
        ];
    }

    public function test_waypoints_are_replaced_and_listed(): void
    {
        $this->as($this->adminToken, 'GET', "/api/admin/routes/{$this->route->id}/waypoints")
            ->assertOk()
            ->assertJsonPath('result.waypoints', [])
            ->assertJsonPath('result.landfill_trips', 0);

        $this->saveWaypoints($this->fullTrip())
            ->assertOk()
            ->assertJsonPath('message', 'Puntos especiales guardados')
            ->assertJsonCount(5, 'result.waypoints')
            ->assertJsonPath('result.waypoints.2.type', 'landfill')
            ->assertJsonPath('result.waypoints.2.sequence', 3)
            ->assertJsonPath('result.waypoints.2.notes', 'Relleno')
            ->assertJsonPath('result.landfill_trips', 1);

        $this->as($this->adminToken, 'GET', "/api/admin/routes/{$this->route->id}/geometry")
            ->assertOk()
            ->assertJsonCount(5, 'result.waypoints')
            ->assertJsonPath('result.landfill_trips', 1);

        $this->as($this->adminToken, 'GET', '/api/admin/routes')
            ->assertOk()
            ->assertJsonPath('result.0.landfill_trips', 1);

        $this->saveWaypoints([])->assertOk()->assertJsonPath('result.waypoints', []);
    }

    public function test_sequence_orders_the_list(): void
    {
        $this->saveWaypoints([
            ['type' => 'end', 'lat' => -4, 'lng' => -79.2, 'sequence' => 9],
            ['type' => 'start', 'lat' => -4, 'lng' => -79.2, 'sequence' => 2],
        ])
            ->assertOk()
            ->assertJsonPath('result.waypoints.0.type', 'start')
            ->assertJsonPath('result.waypoints.0.sequence', 1)
            ->assertJsonPath('result.waypoints.1.sequence', 2);
    }

    public function test_invalid_order_is_rejected(): void
    {
        $p = ['lat' => -4, 'lng' => -79.2];

        $this->saveWaypoints([['type' => 'pause'] + $p])->assertStatus(422);
        $this->saveWaypoints([['type' => 'start'] + $p, ['type' => 'start'] + $p])->assertStatus(422);
        $this->saveWaypoints([['type' => 'start'] + $p, ['type' => 'end'] + $p, ['type' => 'pause'] + $p])->assertStatus(422);
        $this->saveWaypoints([['type' => 'start'] + $p, ['type' => 'resume'] + $p])->assertStatus(422);
        $this->saveWaypoints([['type' => 'start'] + $p, ['type' => 'fly'] + $p])->assertStatus(422);
        $this->as($this->adminToken, 'PUT', "/api/admin/routes/{$this->route->id}/waypoints", [])->assertStatus(422);
    }

    public function test_schedule_waste_type_is_saved_and_returned(): void
    {
        $this->as($this->adminToken, 'PATCH', "/api/admin/routes/{$this->route->id}/schedules", ['days' => [
            ['day_of_week' => 'monday', 'start_time' => '07:00', 'end_time' => '14:30', 'waste_type' => 'organic', 'active' => true],
            ['day_of_week' => 'wednesday', 'start_time' => '07:00', 'end_time' => '14:30', 'waste_type' => 'inorganic'],
        ]])
            ->assertOk()
            ->assertJsonPath('result.schedules.0.waste_type', 'organic');

        $this->as($this->adminToken, 'GET', "/api/admin/routes/{$this->route->id}/schedules")
            ->assertOk()
            ->assertJsonPath('result.schedule.0.waste_type', 'organic')
            ->assertJsonPath('result.schedule.1.waste_type', null)
            ->assertJsonPath('result.schedule.2.waste_type', 'inorganic');

        // Sin waste_type se conserva el valor anterior
        $this->as($this->adminToken, 'POST', "/api/admin/routes/{$this->route->id}/schedules", [
            'day_of_week' => 'monday', 'start_time' => '08:00',
        ])->assertCreated()->assertJsonPath('result.waste_type', 'organic');

        $this->as($this->adminToken, 'POST', "/api/admin/routes/{$this->route->id}/schedules", [
            'day_of_week' => 'monday', 'start_time' => '08:00', 'waste_type' => 'paper',
        ])->assertStatus(422);

        $schedule = Schedule::where('route_id', $this->route->id)->where('day_of_week', 'wednesday')->first();
        $this->as($this->adminToken, 'PUT', "/api/admin/routes/{$this->route->id}/schedules/{$schedule->id}", ['waste_type' => 'both'])
            ->assertOk()
            ->assertJsonPath('result.waste_type', 'both');

        $this->getJson('/api/public/schedules')
            ->assertOk()
            ->assertJsonPath('result.0.schedule.0.waste_type', 'organic');
    }

    public function test_landfill_trip_is_not_off_route_and_is_counted(): void
    {
        $this->saveWaypoints($this->fullTrip())->assertOk();
        $this->as($this->collectorToken, 'POST', '/api/collector/runs/start', ['route_id' => $this->route->id])->assertCreated();

        $this->ping($this->apiKey, 200)->assertJsonPath('result.on_landfill_trip', false);
        $this->ping($this->apiKey, 400)->assertJsonPath('result.on_landfill_trip', true);

        foreach ([500, 1000, 1500] as $east) {
            $res = $this->ping($this->apiKey, 400, $east)
                ->assertJsonPath('result.off_route', false)
                ->assertJsonPath('result.on_landfill_trip', true);
            $this->assertEqualsWithDelta(20, $res->json('result.progress'), 0.5);
        }

        $this->ping($this->apiKey, 400, 2000)->assertJsonPath('result.on_landfill_trip', true);
        $this->ping($this->apiKey, 400, 1000)->assertJsonPath('result.off_route', false);
        $this->ping($this->apiKey, 400, 20)->assertJsonPath('result.on_landfill_trip', false);

        // Sigue dentro del radio de la pausa: no abre otro viaje
        $this->ping($this->apiKey, 450)->assertJsonPath('result.on_landfill_trip', false);
        $res = $this->ping($this->apiKey, 700)->assertJsonPath('result.on_landfill_trip', false);
        $this->assertEqualsWithDelta(70, $res->json('result.progress'), 0.5);

        // Fuera del trazado sin viaje al relleno: desvío normal
        $this->ping($this->apiKey, 720, 200);
        $this->ping($this->apiKey, 740, 200);
        $this->ping($this->apiKey, 760, 200)->assertJsonPath('result.off_route', true);

        $run = RouteRun::latest('id')->first();
        $this->assertSame(1, $run->landfill_trips);
        $this->assertNull($run->detour_state);

        $this->as($this->adminToken, 'GET', "/api/admin/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('result.run.landfill_trips', 1)
            ->assertJsonPath('result.stats.landfill_trips', 1);
    }
}
