<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El dispositivo graba siempre que el camión está encendido: un ping puede no tener ruta.
        Schema::table('collector_tracking', function (Blueprint $table) {
            $table->unsignedBigInteger('route_id')->nullable()->change();
            $table->foreignId('run_id')->nullable()->after('route_id')
                ->constrained('route_runs')->nullOnDelete();
            $table->index(['collector_id', 'recorded_at'], 'ct_collector_time');
            $table->index('run_id', 'ct_run');
        });

        // La sesión pasa a ser una por recolector y día: se conserva la más reciente.
        $duplicates = DB::table('collector_sessions')
            ->select('collector_id', 'tracking_date', DB::raw('MAX(id) as keep_id'))
            ->groupBy('collector_id', 'tracking_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $d) {
            DB::table('collector_sessions')
                ->where('collector_id', $d->collector_id)
                ->where('tracking_date', $d->tracking_date)
                ->where('id', '!=', $d->keep_id)
                ->delete();
        }

        // En MySQL el índice nuevo debe existir antes de borrar el viejo: respalda la FK de collector_id.
        Schema::table('collector_sessions', function (Blueprint $table) {
            $table->unique(['collector_id', 'tracking_date'], 'cs_collector_day_unique');
        });

        Schema::table('collector_sessions', function (Blueprint $table) {
            $table->dropUnique(['collector_id', 'route_id', 'tracking_date']);
        });

        Schema::table('collector_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('route_id')->nullable()->change();
            $table->string('status', 20)->default('idle')->change();

            $table->foreignId('run_id')->nullable()->after('route_id')
                ->constrained('route_runs')->nullOnDelete();

            $table->unsignedInteger('along_m')->nullable()->after('current_longitude');
            $table->unsignedInteger('segment_index')->nullable()->after('along_m');
            $table->decimal('progress', 5, 2)->nullable()->after('segment_index');
            $table->float('distance_to_route_m')->nullable()->after('progress');
            $table->boolean('off_route')->default(false)->after('distance_to_route_m');
            $table->unsignedSmallInteger('off_route_count')->default(0)->after('off_route');
        });
    }

    public function down(): void
    {
        Schema::table('collector_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('run_id');
            $table->dropColumn(['along_m', 'segment_index', 'progress', 'distance_to_route_m', 'off_route', 'off_route_count']);
            $table->unique(['collector_id', 'route_id', 'tracking_date']);
        });

        Schema::table('collector_sessions', function (Blueprint $table) {
            $table->dropUnique('cs_collector_day_unique');
        });

        // La FK debe borrarse antes que el índice que la respalda
        Schema::table('collector_tracking', function (Blueprint $table) {
            $table->dropForeign(['run_id']);
        });

        Schema::table('collector_tracking', function (Blueprint $table) {
            $table->dropIndex('ct_run');
            $table->dropIndex('ct_collector_time');
            $table->dropColumn('run_id');
        });
    }
};
