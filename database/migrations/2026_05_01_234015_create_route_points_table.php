<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->index(['route_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_points');
    }
};
