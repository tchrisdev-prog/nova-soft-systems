<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Models\AuditLog;
use App\Models\Client;
use App\Rules\ColombianNit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class ClientRegistrationTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Banco de Prueba',
            'sector' => 'financial',
            'site_id' => $this->site()->id,
        ], $overrides);
    }

    #[Test]
    public function el_sistema_genera_el_codigo_del_cliente_y_registra_sus_contactos(): void
    {
        $this->actingAs($this->userWithRole('comercial'), 'sanctum');

        $this->postJson('/api/v1/clients', $this->payload([
            'contacts' => [
                ['type' => 'commercial', 'name' => 'Ana Pérez', 'position' => 'Jefa de compras', 'email' => ' ANA.PEREZ@cliente.test '],
                ['type' => 'billing', 'name' => 'Luis Gómez', 'phone' => '+57 601 555 0101'],
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'CLI-001')
            ->assertJsonPath('message', 'Cliente CLI-001 registrado.')
            ->assertJsonCount(2, 'data.contacts')
            ->assertJsonPath('data.primary_contact.name', 'Ana Pérez')
            ->assertJsonPath('data.primary_contact.email', 'ana.perez@cliente.test')
            ->assertJsonPath('data.contacts.1.type.label', 'Facturación');

        $this->postJson('/api/v1/clients', $this->payload(['name' => 'Otro cliente']))->assertJsonPath('data.code', 'CLI-002');
        $this->assertSame(2, AuditLog::query()->where('action', 'client.contact_added')->count());
    }

    #[Test]
    public function los_codigos_continuan_despues_de_los_que_ya_existian(): void
    {
        $this->client(['code' => 'CLI-041']);
        $this->client(['code' => 'DEMO-CLI-900']); // otro formato: no cuenta

        $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->postJson('/api/v1/clients', $this->payload())
            ->assertJsonPath('data.code', 'CLI-042');
    }

    #[Test]
    public function valida_el_digito_de_verificacion_del_nit(): void
    {
        // El NIT público de la DIAN sirve para comprobar el algoritmo.
        $this->assertSame(4, ColombianNit::checkDigit('800197268'));

        $this->actingAs($this->userWithRole('comercial'), 'sanctum');

        $this->postJson('/api/v1/clients', $this->payload(['tax_id' => '900123456-7']))
            ->assertJsonValidationErrors(['tax_id' => 'El dígito de verificación no corresponde al NIT. Revisa que el número esté bien escrito.']);

        $this->postJson('/api/v1/clients', $this->payload(['tax_id' => '900123456']))
            ->assertJsonValidationErrors(['tax_id']);

        // Puntos y espacios se aceptan y se limpian.
        $this->postJson('/api/v1/clients', $this->payload(['tax_id' => '900.123.456 - 8']))
            ->assertCreated()
            ->assertJsonPath('data.tax_id', '900123456-8');

        $this->postJson('/api/v1/clients', $this->payload(['name' => 'Duplicado', 'tax_id' => '900123456-8']))
            ->assertJsonValidationErrors(['tax_id' => 'Ya hay un cliente registrado con ese NIT.']);
    }

    #[Test]
    public function el_codigo_no_se_cambia_y_los_contactos_se_editan_aparte(): void
    {
        $client = $this->client();
        $this->actingAs($this->userWithRole('comercial'), 'sanctum');

        $this->putJson("/api/v1/clients/{$client->id}", ['code' => 'OTRO'])->assertJsonValidationErrors(['code']);
        $this->putJson("/api/v1/clients/{$client->id}", ['contacts' => [['type' => 'commercial', 'name' => 'Ana']]])->assertJsonValidationErrors(['contacts']);
        $this->putJson("/api/v1/clients/{$client->id}", ['name' => 'Nombre nuevo', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre nuevo')
            ->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function el_listado_incluye_el_resumen_comercial_de_cada_cliente(): void
    {
        $client = $this->client();
        $this->monthlyContract(12000000, 40, ['client_id' => $client->id]);
        $this->monthlyContract(8000000, null, ['client_id' => $client->id]);
        $this->hourBagContract(100, 20000000, ['client_id' => $client->id]);
        $this->hourBagContract(50, 9000000, ['client_id' => $client->id, 'status' => 'finished']);

        $this->actingAs($this->userWithRole('comercial'), 'sanctum')
            ->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonPath('data.0.summary.active_contracts', 3)
            ->assertJsonPath('data.0.summary.monthly_recurring', 20000000)
            ->assertJsonPath('data.0.summary.hour_bags', 1)
            ->assertJsonPath('data.0.summary.bag_balance_minutes', 6000);
    }

    #[Test]
    public function el_consultor_no_ve_clientes_ni_sus_contactos(): void
    {
        $client = $this->client();
        $this->actingAs($this->userWithRole('consultor'), 'sanctum');

        $this->getJson("/api/v1/clients/{$client->id}")->assertForbidden();
        $this->getJson("/api/v1/clients/{$client->id}/contacts")->assertForbidden();
        $this->assertSame(1, Client::query()->count());
    }
}
