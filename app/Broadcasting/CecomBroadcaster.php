<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Broadcast driver for apps-cecom.cloud.
 *
 * Publishes each event with:
 *   POST {url}/api/apps/{app_id}/events
 *   X-App-Key / X-App-Secret
 *   { "channel": "...", "event": "...", "data": {...} }
 *
 * Only public channels are supported (no private/presence auth endpoint).
 */
class CecomBroadcaster extends Broadcaster
{
    public function __construct(private readonly array $config) {}

    public function auth($request)
    {
        // Private/presence channels are not supported by this service.
        throw new AccessDeniedHttpException('Canales privados no soportados por el driver cecom');
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        unset($payload['socket']); // Laravel internal, not part of the event data

        $endpoint = rtrim($this->config['url'], '/') . '/api/apps/' . $this->config['app_id'] . '/events';

        foreach ($this->formatChannels($channels) as $channel) {
            $response = Http::timeout($this->config['timeout'] ?? 5)
                ->acceptJson()
                ->withHeaders([
                    'X-App-Key'    => $this->config['key'],
                    'X-App-Secret' => $this->config['secret'],
                ])
                ->post($endpoint, [
                    'channel' => $channel,
                    'event'   => $event,
                    'data'    => $payload,
                ]);

            if ($response->failed()) {
                throw new BroadcastException(sprintf(
                    'Cecom broadcast failed for channel "%s" (HTTP %d): %s',
                    $channel,
                    $response->status(),
                    $response->body()
                ));
            }
        }
    }
}
