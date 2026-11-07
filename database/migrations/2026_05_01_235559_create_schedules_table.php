<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->enum('day_of_week', [
                'monday', 'tuesday', 'wednesday', 'thursday',
                'friday', 'saturday', 'sunday',
            ]);
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // One schedule entry per route per day
            $table->unique(['route_id', 'day_of_week']);

            // Fast lookup: "which routes run on monday?"
            $table->index(['day_of_week', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
