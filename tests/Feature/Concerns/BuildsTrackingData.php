<?php

namespace Tests\Feature\Concerns;

use App\Models\ApiKey;
use App\Models\Route;
use App\Models\User;
use App\Services\RouteGeometryService;
use Illuminate\Testing\TestResponse;

trait BuildsTrackingData
{
    // Ruta recta de ~1000 m hacia el norte, en Loja
    protected const START_LAT = -4.0;
    protected const LNG       = -79.2;
    protected const M_PER_DEG = 111194.93;

    protected function latAt(float $meters): float
    {
        return self::START_LAT + $meters / self::M_PER_DEG;
    }

    protected function makeUser(string $role = 'user', array $attrs = []): User
    {
        return User::create(array_merge([
            'name'     => ucfirst($role) . ' ' . uniqid(),
            'email'    => uniqid($role) . '@example.com',
            'password' => 'Secreta123',
            'role'     => $role,
            'active'   => true,
        ], $attrs))->refresh();
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    protected function apiKeyFor(User $collector): string
    {
        $key = new ApiKey(['name' => 'GPS ' . $collector->id, 'collector_id' => $collector->id, 'active' => true]);
        $plain = $key->fillNewKey();
        $key->save();

        return $plain;
    }

    protected function assignToday(User $collector, Route $route): void
    {
        \App\Models\CollectorRoute::create([
            'collector_id'  => $collector->id,
            'route_id'      => $route->id,
            'assigned_date' => today()->toDateString(),
            'active'        => true,
        ]);
    }

    protected function makeRouteWithGeometry(string $name = 'Ruta 2'): Route
    {
        $route = Route::create(['name' => $name, 'code' => '2', 'active' => true])->refresh();

        app(RouteGeometryService::class)->saveGeometry(
            $route,
            [[$this->latAt(0), self::LNG], [$this->latAt(500), self::LNG], [$this->latAt(1000), self::LNG]],
            'manual'
        );

        return $route->refresh();
    }

    protected function as(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->json($method, $uri, $data);
    }

    protected function ping(string $apiKey, float $meters, float $lngOffsetM = 0, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $lng = self::LNG + $lngOffsetM / (self::M_PER_DEG * cos(deg2rad(self::START_LAT)));

        return $this->withHeaders(['X-API-Key' => $apiKey, 'Accept' => 'application/json'])
            ->postJson('/api/collector/ping', array_merge([
                'latitude'  => $this->latAt($meters),
                'longitude' => $lng,
                'accuracy'  => 5,
            ], $extra));
    }
}
