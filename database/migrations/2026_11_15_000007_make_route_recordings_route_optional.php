<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Sin ruta: la grabación crea una ruta nueva al finalizar
    public function up(): void
    {
        Schema::table('route_recordings', function (Blueprint $table) {
            $table->unsignedBigInteger('route_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('route_recordings', function (Blueprint $table) {
            $table->unsignedBigInteger('route_id')->nullable(false)->change();
        });
    }
};
