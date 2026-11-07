<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CollectorLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('collectors.live'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
