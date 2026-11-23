<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_waypoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->enum('type', ['start', 'pause', 'landfill', 'resume', 'end']);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['route_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_waypoints');
    }
};
