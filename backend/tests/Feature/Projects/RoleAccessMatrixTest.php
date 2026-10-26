<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Enums\TimeEntryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsFixtures;
use Tests\TestCase;

/**
 * La matriz de acceso de las cinco plantillas de rol, probada contra la API
 * real (no contra la interfaz): si una ruta responde 403 aquí, ningún botón
 * en React puede saltarse la regla.
 */
class RoleAccessMatrixTest extends TestCase
{
    use ProjectsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectsModule();
        $this->freezeBusinessTime();
    }

    /** @return array<string, array{string, array<string, int>}> */
    public static function matrix(): array
    {
        // Código HTTP esperado por rol y operación.
        return [
            'gerencia' => ['gerencia', ['clients' => 200, 'create_client' => 201, 'delete_client' => 200, 'projects' => 200, 'review_scope' => 200, 'all_scope' => 200, 'reference' => 200]],
            'subgerencia' => ['subgerencia', ['clients' => 200, 'create_client' => 201, 'delete_client' => 403, 'projects' => 200, 'review_scope' => 200, 'all_scope' => 200, 'reference' => 200]],
            'comercial' => ['comercial', ['clients' => 200, 'create_client' => 201, 'delete_client' => 403, 'projects' => 200, 'review_scope' => 403, 'all_scope' => 403, 'reference' => 200]],
            'lider' => ['lider-de-proyecto', ['clients' => 200, 'create_client' => 403, 'delete_client' => 403, 'projects' => 200, 'review_scope' => 200, 'all_scope' => 403, 'reference' => 200]],
            'consultor' => ['consultor', ['clients' => 403, 'create_client' => 403, 'delete_client' => 403, 'projects' => 403, 'review_scope' => 403, 'all_scope' => 403, 'reference' => 200]],
        ];
    }

    #[Test]
    #[DataProvider('matrix')]
    public function cada_rol_accede_solo_a_lo_que_le_corresponde(string $slug, array $expected): void
    {
        $user = $this->userWithRole($slug);
        $deletable = $this->client();
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/clients')->assertStatus($expected['clients']);
        $this->postJson('/api/v1/clients', [
            'code' => 'nuevo-01', 'name' => 'Cliente nuevo', 'sector' => 'retail', 'site_id' => $this->site()->id,
        ])->assertStatus($expected['create_client']);
        $this->deleteJson("/api/v1/clients/{$deletable->id}")->assertStatus($expected['delete_client']);
        $this->getJson('/api/v1/projects')->assertStatus($expected['projects']);
        $this->getJson('/api/v1/time-entries?scope=review')->assertStatus($expected['review_scope']);
        $this->getJson('/api/v1/time-entries?scope=all')->assertStatus($expected['all_scope']);
        $this->getJson('/api/v1/reference-data')->assertStatus($expected['reference']);
    }

    #[Test]
    public function el_lider_solo_ve_los_proyectos_que_lidera(): void
    {
        $leader = $this->userWithRole('lider-de-proyecto');
        $mine = $this->project($this->hourBagContract(), $leader);
        $other = $this->project($this->hourBagContract(), $this->userWithRole('lider-de-proyecto'));

        $this->actingAs($leader, 'sanctum');

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson("/api/v1/projects/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/projects/{$other->id}")->assertForbidden();
    }

    #[Test]
    public function el_costo_de_las_horas_solo_lo_ve_quien_tiene_permiso_de_costos(): void
    {
        $consultant = $this->userWithRole('consultor');
        $project = $this->project($this->hourBagContract(), $this->userWithRole('lider-de-proyecto'));
        $this->entry($consultant, $project, TimeEntryStatus::Approved, ['cost_rate' => 90000]);

        $own = $this->actingAs($consultant, 'sanctum')->getJson('/api/v1/time-entries')->assertOk();
        $this->assertArrayNotHasKey('cost', $own->json('data.0'));
        $this->assertArrayNotHasKey('cost_rate', $own->json('data.0'));
        $this->assertArrayNotHasKey('approved_cost', $this->getJson('/api/v1/time-entries/summary')->json('data'));

        $manager = $this->userWithRole('gerencia');
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/time-entries?scope=all')
            ->assertOk()
            ->assertJsonPath('data.0.cost_rate', 90000)
            ->assertJsonPath('data.0.cost', 180000);
    }

    #[Test]
    public function un_consultor_solo_ve_sus_propias_horas(): void
    {
        $project = $this->project($this->hourBagContract(), $this->userWithRole('lider-de-proyecto'));
        $me = $this->userWithRole('consultor');
        $colleague = $this->userWithRole('consultor');
        $this->entry($me, $project);
        $this->entry($colleague, $project);

        $this->actingAs($me, 'sanctum')->getJson('/api/v1/time-entries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $me->id);

        // Aunque pida las de otra persona por filtro, el alcance "mine" manda.
        $this->getJson("/api/v1/time-entries?user_id={$colleague->id}")->assertOk()->assertJsonCount(0, 'data');
    }
}
