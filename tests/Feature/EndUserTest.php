<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsTrackingData;
use Tests\TestCase;

class EndUserTest extends TestCase
{
    use RefreshDatabase, BuildsTrackingData;

    public function test_register_profile_and_delete_account(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'name' => 'María', 'email' => 'maria@example.com',
            'password' => 'Secreta123', 'password_confirmation' => 'Secreta123',
        ])->assertCreated()
            ->assertJsonPath('code', 1)
            ->assertJsonPath('result.user.role', 'user')
            ->assertJsonPath('result.user.notifications_enabled', true);

        $token = $res->json('result.token');

        $this->postJson('/api/auth/register', [
            'name' => 'Otra', 'email' => 'maria@example.com',
            'password' => 'Secreta123', 'password_confirmation' => 'Secreta123',
        ])->assertStatus(422)->assertJsonValidationErrors('email', 'result');

        $this->as($token, 'PUT', '/api/auth/me', ['name' => 'María L.', 'phone' => '0991234567'])
            ->assertOk()->assertJsonPath('result.name', 'María L.');

        $this->as($token, 'PUT', '/api/auth/me', ['password' => 'Nueva12345', 'password_confirmation' => 'Nueva12345'])
            ->assertStatus(422);

        $this->as($token, 'PUT', '/api/auth/me', [
            'current_password' => 'Secreta123', 'password' => 'Nueva12345', 'password_confirmation' => 'Nueva12345',
        ])->assertOk();

        $this->as($token, 'DELETE', '/api/auth/me', ['password' => 'mala'])->assertStatus(422);
        $this->as($token, 'DELETE', '/api/auth/me', ['password' => 'Nueva12345'])->assertOk();
        $this->assertNull(User::where('email', 'maria@example.com')->first());
    }

    public function test_collector_cannot_delete_own_account(): void
    {
        $token = $this->tokenFor($this->makeUser('collector'));

        $this->as($token, 'DELETE', '/api/auth/me', ['password' => 'Secreta123'])->assertForbidden();
    }

    public function test_locations_crud_and_switches(): void
    {
        $this->makeRouteWithGeometry();
        $user  = $this->makeUser();
        $token = $this->tokenFor($user);

        $id = $this->as($token, 'POST', '/api/me/locations', [
            'label' => 'Casa', 'latitude' => $this->latAt(300), 'longitude' => self::LNG,
        ])->assertCreated()
            ->assertJsonPath('result.served', true)
            ->assertJsonPath('result.notify_enabled', true)
            ->assertJsonPath('result.notify_distance_m', 500)
            ->assertJsonPath('result.routes.0.code', '2')
            ->json('result.id');

        // Sin límite de ubicaciones; una lejos de toda ruta no queda servida
        $this->as($token, 'POST', '/api/me/locations', [
            'label' => 'Trabajo', 'latitude' => -3.95, 'longitude' => -79.21,
        ])->assertCreated()->assertJsonPath('result.served', false);

        $this->as($token, 'GET', '/api/me/locations')->assertOk()->assertJsonCount(2, 'result');

        $this->as($token, 'PATCH', "/api/me/locations/{$id}/notifications", ['enabled' => false])
            ->assertOk()->assertJsonPath('result.notify_enabled', false);

        $this->as($token, 'PATCH', "/api/me/locations/{$id}/notifications", ['enabled' => true, 'distance_m' => 3000])
            ->assertStatus(422);

        $this->as($token, 'PATCH', "/api/me/locations/{$id}/notifications", ['enabled' => true, 'distance_m' => 800])
            ->assertOk()->assertJsonPath('result.notify_distance_m', 800);

        $this->as($token, 'PUT', "/api/me/locations/{$id}", ['latitude' => -3.95, 'longitude' => -79.21])
            ->assertOk()->assertJsonPath('result.served', false);

        $this->as($token, 'PATCH', '/api/me/notifications/settings', ['enabled' => false])
            ->assertOk()->assertJsonPath('result.enabled', false);

        // Otro usuario no ve ni edita mis ubicaciones
        $other = $this->tokenFor($this->makeUser());
        $this->as($other, 'GET', "/api/me/locations/{$id}")->assertNotFound();
        $this->as($other, 'DELETE', "/api/me/locations/{$id}")->assertNotFound();

        $this->as($token, 'DELETE', "/api/me/locations/{$id}")->assertOk();
    }

    public function test_push_subscriptions_and_notification_history(): void
    {
        $user  = $this->makeUser();
        $token = $this->tokenFor($user);

        $sub = ['endpoint' => 'https://push.example.com/xyz', 'keys' => ['p256dh' => 'BPk', 'auth' => 'au']];

        $this->as($token, 'POST', '/api/me/push-subscriptions', $sub)->assertCreated();
        $this->as($token, 'POST', '/api/me/push-subscriptions', $sub)->assertCreated();
        $this->assertSame(1, PushSubscription::count());

        $this->as($token, 'POST', '/api/me/push-subscriptions', ['endpoint' => 'http://inseguro.com', 'keys' => $sub['keys']])
            ->assertStatus(422);

        $this->as($token, 'GET', '/api/me/notifications/settings')->assertOk()->assertJsonPath('result.push_devices', 1);

        $user->notify(new \App\Notifications\CollectorNearbyNotification(['title' => 'T', 'body' => 'B']));

        $list = $this->as($token, 'GET', '/api/me/notifications')->assertOk()
            ->assertJsonPath('result.unread_count', 1)
            ->assertJsonPath('result.items.0.type', 'collector_nearby')
            ->assertJsonPath('result.items.0.title', 'T');

        $this->as($token, 'POST', '/api/me/notifications/' . $list->json('result.items.0.id') . '/read')->assertOk();
        $this->as($token, 'GET', '/api/me/notifications?unread=1')->assertOk()->assertJsonPath('result.pagination.total', 0);

        $this->as($token, 'DELETE', '/api/me/push-subscriptions', ['endpoint' => $sub['endpoint']])
            ->assertOk()->assertJsonPath('result.deleted', true);

        $this->getJson('/api/public/push/public-key')->assertOk()->assertJsonStructure(['result' => ['public_key']]);
    }
}
