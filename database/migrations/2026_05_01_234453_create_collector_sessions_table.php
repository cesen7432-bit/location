<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Current state per collector per route per day.
        // One row, updated on every ping — fast reads for the live map.
        Schema::create('collector_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collector_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->date('tracking_date');

            // Current position
            $table->decimal('current_latitude', 10, 7)->nullable();
            $table->decimal('current_longitude', 10, 7)->nullable();

            // Session state
            $table->enum('status', ['on_route', 'paused', 'completed'])->default('on_route');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Throttle state — last position that was actually broadcast via WebSocket
            $table->decimal('last_broadcast_lat', 10, 7)->nullable();
            $table->decimal('last_broadcast_lng', 10, 7)->nullable();
            $table->timestamp('last_broadcast_at')->nullable();

            $table->timestamps();

            $table->unique(['collector_id', 'route_id', 'tracking_date']);
            $table->index(['tracking_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_sessions');
    }
};
