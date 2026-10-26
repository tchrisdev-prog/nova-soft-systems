<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes, contratos y todo lo que deja rastro de un contrato:
 *
 * - contract_hour_movements: la bolsa de horas como un extracto. Nunca se
 *   edita una línea; una recarga o una corrección es una línea nueva.
 * - contract_periods: cada mes de una mensualidad, con el valor y las horas
 *   que se pactaron ese mes (si las condiciones cambian, los meses pasados
 *   conservan las suyas).
 * - contract_revisions: el historial de cambios del contrato, escrito en la
 *   misma transacción que el cambio, para que nunca quede un cambio sin rastro.
 *
 * Montos en pesos colombianos (enteros) y horas en minutos (enteros).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('tax_id', 20)->nullable()->unique();   // NIT
            $table->string('sector', 20);
            $table->foreignId('site_id')->constrained()->restrictOnDelete(); // sede que atiende la cuenta
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('service_line', 30)->index();
            $table->string('billing_model', 30);
            $table->string('coverage', 20);
            $table->string('status', 20)->index();
            $table->date('start_date');
            $table->date('end_date')->nullable();               // vacío = se renueva mes a mes
            $table->unsignedBigInteger('monthly_fee')->nullable();          // solo mensualidades
            $table->unsignedInteger('included_minutes_per_month')->nullable(); // vacío = solo disponibilidad
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('contract_hour_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->integer('minutes');                        // un ajuste puede restar
            $table->bigInteger('amount')->default(0);          // valor de la compra en COP
            $table->date('reference_date');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['contract_id', 'reference_date']);
        });

        Schema::create('contract_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('fee_amount');
            $table->unsignedInteger('included_minutes')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'period_start']);
        });

        Schema::create('contract_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('changes')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['contract_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_revisions');
        Schema::dropIfExists('contract_periods');
        Schema::dropIfExists('contract_hour_movements');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('clients');
    }
};
