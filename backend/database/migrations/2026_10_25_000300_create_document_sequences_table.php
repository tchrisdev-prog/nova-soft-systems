<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutivos para los códigos que genera el sistema (CLI-001, CT-2026-001).
 * Una fila por serie, bloqueada al pedir el siguiente número, para que dos
 * personas creando un contrato al mismo tiempo nunca reciban el mismo código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('series', 40)->primary();   // "client", "contract-2026"
            $table->unsignedBigInteger('last_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
