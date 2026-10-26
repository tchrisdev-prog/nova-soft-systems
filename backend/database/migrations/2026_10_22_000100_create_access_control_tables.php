<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo de autorización: módulos → permisos (módulo + acción) → roles → usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();           // p. ej. "access_control"
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('status', 20)->default('planned')->index(); // active | planned | inactive
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);                    // view | create | update | delete | manage | ...
            $table->string('code', 120)->unique();          // "<modulo>.<accion>"
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['module_id', 'action']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('slug', 90)->unique();           // identificador estable, no cambia al renombrar
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(false);   // roles protegidos (super-admin)
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['role_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('modules');
    }
};
