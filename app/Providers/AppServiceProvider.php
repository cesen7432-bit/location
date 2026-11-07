<?php

namespace App\Providers;

use App\Broadcasting\CecomBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Custom broadcast driver: BROADCAST_CONNECTION=cecom
        Broadcast::extend('cecom', fn ($app, array $config) => new CecomBroadcaster($config));
    }
}
