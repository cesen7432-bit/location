<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['schedule_days', 'schedule_start_time', 'schedule_end_time']);
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->json('schedule_days')->nullable()->after('description');
            $table->time('schedule_start_time')->nullable()->after('schedule_days');
            $table->time('schedule_end_time')->nullable()->after('schedule_start_time');
        });
    }
};
