<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('collector_tracking');

        // Raw breadcrumb log — every GPS ping from the collector.
        // Grows fast; one row per ping. Do NOT add unique constraints here.
        Schema::create('collector_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collector_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('accuracy')->nullable(); // GPS accuracy in meters
            $table->date('tracking_date');
            $table->timestamp('recorded_at');

            // Query patterns: fetch all pings for a collector+route+day
            $table->index(['collector_id', 'route_id', 'tracking_date', 'recorded_at'], 'ct_collector_route_day');
            // Query pattern: fetch all active pings today (dashboard)
            $table->index('tracking_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_tracking');
    }
};
