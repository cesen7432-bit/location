<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collector_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->date('assigned_date');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['collector_id', 'route_id', 'assigned_date']);
            $table->index(['collector_id', 'assigned_date']);
            $table->index(['route_id', 'assigned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_routes');
    }
};
