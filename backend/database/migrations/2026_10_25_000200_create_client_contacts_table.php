<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un cliente real tiene varias personas de contacto: quien negocia, quien
 * reporta los incidentes y quien recibe las facturas. Esta migración crea la
 * tabla de contactos y pasa a ella el contacto único que ya existía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                 // comercial, técnico, facturación...
            $table->string('name', 120);
            $table->string('position', 100)->nullable(); // cargo dentro del cliente
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['client_id', 'is_primary']);
        });

        // El contacto que ya tenía cada cliente pasa a ser su contacto principal.
        DB::table('clients')
            ->where(fn ($q) => $q->whereNotNull('contact_name')->orWhereNotNull('contact_email')->orWhereNotNull('contact_phone'))
            ->orderBy('id')
            ->each(function ($client) {
                DB::table('client_contacts')->insert([
                    'client_id' => $client->id,
                    'type' => 'commercial',
                    'name' => $client->contact_name ?: 'Contacto principal',
                    'email' => $client->contact_email,
                    'phone' => $client->contact_phone,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_email', 'contact_phone']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
        });

        DB::table('client_contacts')->where('is_primary', true)->orderBy('id')->each(function ($contact) {
            DB::table('clients')->where('id', $contact->client_id)->update([
                'contact_name' => $contact->name,
                'contact_email' => $contact->email,
                'contact_phone' => $contact->phone,
            ]);
        });

        Schema::dropIfExists('client_contacts');
    }
};
