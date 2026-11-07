<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // API keys used by devices (e.g. GPS trackers) to send pings without a user session.
        // Only the SHA-256 hash of the key is stored; the plain key is shown once on creation.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // Alias / nombre descriptivo
            $table->foreignId('collector_id')->nullable() // Recolector asociado (opcional)
                ->constrained('users')->nullOnDelete();
            $table->string('key_prefix', 12);             // Primeros caracteres, para identificarla visualmente
            $table->string('key_hash', 64)->unique();     // sha256 de la key completa
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
