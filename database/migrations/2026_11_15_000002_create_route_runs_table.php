<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collector_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress');

            $table->timestamp('started_at');
            $table->decimal('start_latitude', 10, 7)->nullable();
            $table->decimal('start_longitude', 10, 7)->nullable();
            $table->string('start_source', 10)->nullable();

            $table->timestamp('ended_at')->nullable();
            $table->decimal('end_latitude', 10, 7)->nullable();
            $table->decimal('end_longitude', 10, 7)->nullable();
            $table->string('end_source', 10)->nullable();
            $table->string('closed_by', 10)->nullable();

            $table->unsignedInteger('ping_count')->default(0);
            $table->unsignedInteger('distance_m')->nullable();
            $table->decimal('max_progress', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['collector_id', 'status']);
            $table->index(['route_id', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_runs');
    }
};
