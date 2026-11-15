<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WebPushService $push) {}

    /**
     * @OA\Get(path="/api/public/push/public-key", tags={"Public"}, summary="Clave pública VAPID",
     *     description="El frontend la usa en pushManager.subscribe({applicationServerKey}).",
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function publicKey(): JsonResponse
    {
        if (!$this->push->isConfigured()) {
            return $this->error('Las notificaciones push no están configuradas en el servidor', null, 503);
        }

        return $this->success(['public_key' => $this->push->publicKey()]);
    }

    /**
     * @OA\Get(path="/api/me/notifications/settings", tags={"Me"}, summary="Estado de mis notificaciones",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function settings(Request $request): JsonResponse
    {
        return $this->success($this->settingsResource($request));
    }

    /**
     * @OA\Patch(path="/api/me/notifications/settings", tags={"Me"}, summary="Activar/desactivar todas mis notificaciones",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"enabled"}, @OA\Property(property="enabled", type="boolean", example=false))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);

        $request->user()->update(['notifications_enabled' => $data['enabled']]);

        return $this->success(
            $this->settingsResource($request),
            $data['enabled'] ? 'Notificaciones activadas' : 'Notificaciones desactivadas'
        );
    }

    /**
     * @OA\Get(path="/api/me/notifications", tags={"Me"}, summary="Historial de notificaciones",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="unread", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", example=20)),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'unread'   => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $page = ($request->boolean('unread') ? $user->unreadNotifications() : $user->notifications())
            ->paginate((int) $request->input('per_page', 20));

        return $this->success([
            'items'        => collect($page->items())->map(fn ($n) => $this->notificationResource($n))->values(),
            'unread_count' => $user->unreadNotifications()->count(),
            'pagination'   => [
                'page'      => $page->currentPage(),
                'per_page'  => $page->perPage(),
                'total'     => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @OA\Post(path="/api/me/notifications/{id}/read", tags={"Me"}, summary="Marcar notificación como leída",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $this->success($this->notificationResource($notification), 'Notificación leída');
    }

    /**
     * @OA\Post(path="/api/me/notifications/read-all", tags={"Me"}, summary="Marcar todas como leídas",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function readAll(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->success(['marked' => $count], 'Notificaciones leídas');
    }

    /**
     * @OA\Post(path="/api/me/push-subscriptions", tags={"Me"}, summary="Registrar dispositivo para push",
     *     description="Enviar tal cual el resultado de pushManager.subscribe(...).toJSON().",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"endpoint","keys"},
     *         @OA\Property(property="endpoint", type="string", example="https://fcm.googleapis.com/fcm/send/abc..."),
     *         @OA\Property(property="keys", type="object",
     *             @OA\Property(property="p256dh", type="string"),
     *             @OA\Property(property="auth", type="string")),
     *         @OA\Property(property="content_encoding", type="string", example="aes128gcm"))),
     *     @OA\Response(response=201, description="Registrado", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint'         => 'required|url:https|max:2000',
            'keys.p256dh'      => 'required|string|max:255',
            'keys.auth'        => 'required|string|max:255',
            'content_encoding' => 'nullable|in:aesgcm,aes128gcm',
        ]);

        // Un mismo navegador puede cambiar de cuenta: la suscripción pasa al usuario actual
        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id'          => $request->user()->id,
                'endpoint'         => $data['endpoint'],
                'public_key'       => $data['keys']['p256dh'],
                'auth_token'       => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'user_agent'       => substr((string) $request->userAgent(), 0, 255) ?: null,
            ]
        );

        return $this->created([
            'id'         => $subscription->id,
            'created_at' => $subscription->created_at?->toIso8601String(),
        ], 'Dispositivo registrado para notificaciones');
    }

    /**
     * @OA\Delete(path="/api/me/push-subscriptions", tags={"Me"}, summary="Quitar dispositivo",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"endpoint"}, @OA\Property(property="endpoint", type="string"))),
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000']);

        $deleted = $request->user()->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return $this->success(['deleted' => $deleted > 0], 'Dispositivo eliminado');
    }

    /**
     * @OA\Post(path="/api/me/push-subscriptions/test", tags={"Me"}, summary="Enviar push de prueba",
     *     description="Envía una notificación de prueba a todos mis dispositivos.",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="OK", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function test(Request $request): JsonResponse
    {
        if (!$this->push->isConfigured()) {
            return $this->error('Las notificaciones push no están configuradas en el servidor', null, 503);
        }

        $subscriptions = $request->user()->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return $this->error('No tienes dispositivos registrados', null, 422);
        }

        $payload = [
            'title' => 'Notificación de prueba',
            'body'  => 'Las notificaciones están funcionando.',
            'tag'   => 'test',
            'data'  => ['type' => 'test'],
        ];

        $sent = $this->push->sendBatch($subscriptions->map(fn ($s) => [$s, $payload])->all());

        return $this->success(['devices' => $subscriptions->count(), 'sent' => $sent], 'Prueba enviada');
    }

    private function settingsResource(Request $request): array
    {
        $user = $request->user()->fresh();

        return [
            'enabled'          => (bool) $user->notifications_enabled,
            'push_configured'  => $this->push->isConfigured(),
            'push_devices'     => $user->pushSubscriptions()->count(),
            'locations'        => $user->locations()->count(),
            'locations_notify' => $user->locations()->where('notify_enabled', true)->count(),
        ];
    }

    private function notificationResource(DatabaseNotification $n): array
    {
        return [
            'id'         => $n->id,
            'type'       => $n->type,
            'title'      => $n->data['title'] ?? null,
            'body'       => $n->data['body'] ?? null,
            'data'       => $n->data,
            'read_at'    => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
