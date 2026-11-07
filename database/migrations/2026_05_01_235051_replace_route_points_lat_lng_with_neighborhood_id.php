<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // route_points is empty at this point (no data yet), so we can
        // drop and recreate it cleanly referencing neighborhoods.
        Schema::dropIfExists('route_points');

        Schema::create('route_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('neighborhood_id')->constrained('neighborhoods')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->text('notes')->nullable(); // Optional stop-specific note
            $table->timestamps();

            $table->unique(['route_id', 'sequence']);
            $table->unique(['route_id', 'neighborhood_id']); // Same neighborhood once per route
            $table->index(['route_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_points');

        Schema::create('route_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['route_id', 'sequence']);
        });
    }
};
