<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('recording');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('point_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['route_id', 'status']);
        });

        Schema::create('route_recording_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_recording_id')->constrained('route_recordings')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('accuracy')->nullable();
            $table->timestamp('recorded_at', 3);

            // Reenviar un tramo (p. ej. tras un corte de red) no duplica puntos
            $table->unique(['route_recording_id', 'recorded_at'], 'rrp_recording_time_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_recording_points');
        Schema::dropIfExists('route_recordings');
    }
};
