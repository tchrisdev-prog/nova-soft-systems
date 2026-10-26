<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sedes de la empresa y perfil laboral de cada consultor.
 *
 * El perfil vive aparte de "users" a propósito: la tabla de usuarios es de
 * acceso (quién entra), y el perfil es de operación (dónde trabaja, cuántas
 * horas tiene disponibles y cuánto cuesta su hora).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();            // medellin, bogota, remoto
            $table->string('name', 80);
            $table->string('city', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('position', 100);                  // cargo
            $table->unsignedInteger('weekly_capacity_minutes'); // 42 h = 2.520 min
            $table->unsignedBigInteger('hourly_cost')->nullable(); // COP por hora, dato sensible
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('sites');
    }
};
