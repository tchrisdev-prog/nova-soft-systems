<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

class ClientContactsTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->client = $this->client();
        $this->actingAs($this->userWithRole('comercial'), 'sanctum');
    }

    private function add(array $data): int
    {
        return $this->postJson("/api/v1/clients/{$this->client->id}/contacts", $data)->assertCreated()->json('data.id');
    }

    #[Test]
    public function el_primer_contacto_es_el_principal_y_solo_puede_haber_uno(): void
    {
        $first = $this->add(['type' => 'commercial', 'name' => 'Ana Pérez']);
        $second = $this->add(['type' => 'technical', 'name' => 'Carlos Ruiz']);

        $this->assertTrue(ClientContact::query()->find($first)->is_primary);
        $this->assertFalse(ClientContact::query()->find($second)->is_primary);

        $this->putJson("/api/v1/clients/{$this->client->id}/contacts/{$second}", ['is_primary' => true])->assertOk();

        $this->assertFalse(ClientContact::query()->find($first)->is_primary);
        $this->assertTrue(ClientContact::query()->find($second)->is_primary);
        $this->assertSame(1, ClientContact::query()->where('is_primary', true)->count());
    }

    #[Test]
    public function al_borrar_el_principal_otro_contacto_toma_su_lugar(): void
    {
        $first = $this->add(['type' => 'commercial', 'name' => 'Ana Pérez']);
        $second = $this->add(['type' => 'billing', 'name' => 'Luis Gómez']);

        $this->deleteJson("/api/v1/clients/{$this->client->id}/contacts/{$first}")->assertOk();

        $this->assertTrue(ClientContact::query()->find($second)->is_primary);
        $this->getJson("/api/v1/clients/{$this->client->id}")->assertJsonPath('data.primary_contact.name', 'Luis Gómez');
    }

    #[Test]
    public function valida_los_datos_del_contacto(): void
    {
        $this->postJson("/api/v1/clients/{$this->client->id}/contacts", ['type' => 'nadie', 'name' => '', 'email' => 'no-es-correo', 'phone' => 'abc'])
            ->assertJsonValidationErrors(['type', 'name', 'email', 'phone']);
    }

    #[Test]
    public function un_contacto_solo_se_edita_desde_su_propio_cliente(): void
    {
        $contact = $this->add(['type' => 'commercial', 'name' => 'Ana Pérez']);
        $other = $this->client();

        $this->putJson("/api/v1/clients/{$other->id}/contacts/{$contact}", ['name' => 'Cambiado'])->assertNotFound();
        $this->deleteJson("/api/v1/clients/{$other->id}/contacts/{$contact}")->assertNotFound();
        $this->assertSame('Ana Pérez', ClientContact::query()->find($contact)->name);
    }

    #[Test]
    public function editar_contactos_exige_poder_editar_el_cliente(): void
    {
        $contact = $this->add(['type' => 'commercial', 'name' => 'Ana Pérez']);
        $this->actingAs($this->userWithRole('lider-de-proyecto'), 'sanctum');

        // El líder consulta clientes (y sus contactos), pero no los modifica.
        $this->getJson("/api/v1/clients/{$this->client->id}/contacts")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/clients/{$this->client->id}/contacts", ['type' => 'technical', 'name' => 'Otro'])->assertForbidden();
        $this->putJson("/api/v1/clients/{$this->client->id}/contacts/{$contact}", ['name' => 'Otro'])->assertForbidden();
        $this->deleteJson("/api/v1/clients/{$this->client->id}/contacts/{$contact}")->assertForbidden();
    }
}
