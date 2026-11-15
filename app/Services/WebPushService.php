<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push con VAPID: llega al navegador o PWA aunque la app esté cerrada, sin Firebase.
 */
class WebPushService
{
    public function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public function publicKey(): ?string
    {
        return config('services.webpush.public_key');
    }

    /**
     * @param  array<int, array{0: PushSubscription, 1: array}>  $items
     */
    public function sendBatch(array $items): int
    {
        if (!$items || !$this->isConfigured()) {
            return 0;
        }

        $webPush = new WebPush(
            [
                'VAPID' => [
                    'subject'    => config('services.webpush.subject') ?: config('app.url'),
                    'publicKey'  => config('services.webpush.public_key'),
                    'privateKey' => config('services.webpush.private_key'),
                ],
            ],
            ['TTL' => 600, 'urgency' => 'high'],
            (int) config('services.webpush.timeout', 5)
        );

        foreach ($items as [$subscription, $payload]) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint'        => $subscription->endpoint,
                    'publicKey'       => $subscription->public_key,
                    'authToken'       => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
        }

        $sent    = 0;
        $expired = [];
        $used    = [];

        foreach ($webPush->flush() as $report) {
            $hash = PushSubscription::hashEndpoint($report->getEndpoint());

            if ($report->isSuccess()) {
                $sent++;
                $used[] = $hash;
            } elseif ($report->isSubscriptionExpired()) {
                $expired[] = $hash;
            } else {
                Log::warning('Web Push falló', ['reason' => $report->getReason()]);
            }
        }

        if ($expired) {
            PushSubscription::whereIn('endpoint_hash', $expired)->delete();
        }

        if ($used) {
            PushSubscription::whereIn('endpoint_hash', $used)->update(['last_used_at' => now()]);
        }

        return $sent;
    }
}
