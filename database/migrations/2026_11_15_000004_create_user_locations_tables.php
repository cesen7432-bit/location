<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notifications_enabled')->default(true)->after('active');
        });

        Schema::create('user_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('notify_enabled')->default(true);
            $table->unsignedSmallInteger('notify_distance_m')->default(500);
            $table->boolean('garbage_out')->default(false);
            $table->timestamp('garbage_out_at')->nullable();
            $table->timestamp('garbage_collected_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['latitude', 'longitude']);
            $table->index('garbage_out');
        });

        // Se precalcula al crear/mover la ubicación o al cambiar el trazado, para que
        // cada ping solo haga una consulta por rango sobre (route_id, along_m).
        Schema::create('location_route_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_location_id')->constrained('user_locations')->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->unsignedInteger('along_m');
            $table->float('distance_m');
            $table->foreignId('last_notified_run_id')->nullable()->constrained('route_runs')->nullOnDelete();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_location_id', 'route_id']);
            $table->index(['route_id', 'along_m']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_route_matches');
        Schema::dropIfExists('user_locations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_enabled');
        });
    }
};
