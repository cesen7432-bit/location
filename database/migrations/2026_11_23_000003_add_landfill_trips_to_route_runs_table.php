<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('landfill_trips')->default(0)->after('max_progress');
            // Viaje al relleno en curso: null | pause (yendo) | landfill (ya llegó, volviendo)
            $table->string('detour_state', 10)->nullable()->after('landfill_trips');
            $table->timestamp('detour_started_at')->nullable()->after('detour_state');
        });
    }

    public function down(): void
    {
        Schema::table('route_runs', function (Blueprint $table) {
            $table->dropColumn(['landfill_trips', 'detour_state', 'detour_started_at']);
        });
    }
};
