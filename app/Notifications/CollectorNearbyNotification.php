<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CollectorNearbyNotification extends Notification
{
    public function __construct(public readonly array $payload) {}

    // El push al dispositivo se envía en lote desde NearbyCollectorNotifier
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'collector_nearby';
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
