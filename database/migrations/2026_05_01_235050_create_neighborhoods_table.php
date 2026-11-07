<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('municipality')->nullable(); // Municipio / Ciudad
            $table->string('zone')->nullable();         // Zona / Sector
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['municipality', 'zone']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neighborhoods');
    }
};
