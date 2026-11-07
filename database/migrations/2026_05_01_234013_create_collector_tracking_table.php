<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collector_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('route_point_id')->nullable()->constrained('route_points')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->date('tracking_date');
            $table->enum('status', ['on_route', 'paused', 'completed'])->default('on_route');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['collector_id', 'route_id', 'tracking_date']);
            $table->index(['tracking_date', 'status']);
            $table->index('collector_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_tracking');
    }
};
