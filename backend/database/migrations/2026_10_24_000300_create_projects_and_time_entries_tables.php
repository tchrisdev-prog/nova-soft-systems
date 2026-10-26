<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proyectos, asignaciones de consultores y registro de horas.
 *
 * Los índices están pensados para las consultas que más se repiten:
 * "horas de este proyecto entre estas fechas" (consumo de contratos) y
 * "horas de esta persona esta semana" (capacidad y registro diario).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('kind', 20);
            $table->string('status', 20)->index();
            $table->foreignId('leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('budget_minutes')->nullable();
            $table->string('description', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('leader_id');
            $table->index('contract_id');
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_label', 80)->nullable();      // rol dentro del proyecto
            $table->unsignedTinyInteger('allocation_percent');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'start_date']);
            $table->index('project_id');
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedSmallInteger('minutes');
            $table->string('description', 500);
            $table->boolean('is_billable')->default(true);
            $table->boolean('is_after_hours')->default(false); // fuera de horario
            $table->boolean('is_on_call')->default(false);     // disponibilidad
            $table->string('status', 20);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            // Costo de la hora del consultor el día que se aprobó. Así un
            // aumento de tarifa no cambia la rentabilidad de meses anteriores.
            $table->unsignedBigInteger('cost_rate')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status', 'work_date']);
            $table->index(['user_id', 'work_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('projects');
    }
};
