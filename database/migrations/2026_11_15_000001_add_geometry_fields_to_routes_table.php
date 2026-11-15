<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('name');
            $table->string('shift', 10)->nullable()->after('zone');
            // GeoJSON LineString ya simplificado: [lng, lat]
            $table->json('geometry')->nullable()->after('shift');
            $table->string('geometry_source', 20)->nullable()->after('geometry');
            $table->unsignedInteger('distance_m')->nullable()->after('geometry_source');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['code', 'shift', 'geometry', 'geometry_source', 'distance_m']);
        });
    }
};
