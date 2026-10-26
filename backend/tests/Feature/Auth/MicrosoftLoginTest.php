<?php

namespace Tests\Feature\Auth;

use App\Auth\EntraId\EntraIdConfig;
use App\Auth\EntraId\EntraIdProvider;
use App\Auth\EntraId\IdTokenValidator;
use App\Models\AuditLog;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EntraIdTokens;
use Tests\TestCase;

class MicrosoftLoginTest extends TestCase
{
    use EntraIdTokens, RefreshDatabase;

    private const CALLBACK = '/auth/microsoft/callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->configureEntraId();
        Http::fake([$this->jwksUrl() => Http::response($this->jwks())]);
    }

    /**
     * Sustituye solo el cliente HTTP con el que el proveedor canjea el código
     * (no hay conexión con Microsoft en las pruebas). El resto del flujo
     * (state, nonce, validación del id_token) es el real.
     */
    private function fakeTokenEndpoint(string $idToken): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'token_type' => 'Bearer',
                'access_token' => 'not-used',
                'id_token' => $idToken,
            ])),
        ]))]);

        Socialite::forgetDrivers();
        Socialite::extend('entra', function ($app) use ($client) {
            $config = $app->make(EntraIdConfig::class);

            return Socialite::buildProvider(EntraIdProvider::class, [
                'client_id' => $config->clientId,
                'client_secret' => $config->clientSecret,
                'redirect' => $config->redirectUri,
            ])->withEntraConfig($config, $app->make(IdTokenValidator::class))->setHttpClient($client);
        });
    }

    private function returnFromMicrosoft(string $idToken, string $nonce = 'expected-nonce')
    {
        $this->fakeTokenEndpoint($idToken);

        return $this->withSession(['state' => 'state-123', 'entra_nonce' => $nonce, 'code_verifier' => 'verifier'])
            ->get(self::CALLBACK.'?code=auth-code&state=state-123');
    }

    #[Test]
    public function informa_que_la_autenticacion_no_esta_configurada(): void
    {
        config(['services.microsoft.client_secret' => null]);

        $this->get('/auth/microsoft/redirect')
            ->assertRedirect('http://localhost:5173/login?error=not_configured');

        $this->getJson('/api/v1/auth/config')
            ->assertOk()
            ->assertJsonPath('data.configured', false);
    }

    #[Test]
    public function redirige_a_microsoft_con_pkce_state_y_nonce(): void
    {
        $response = $this->get('/auth/microsoft/redirect');

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize?", $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame($this->clientId, $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringContainsString('openid', $query['scope']);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame(session('entra_nonce'), $query['nonce']);
        $this->assertNotEmpty(session('code_verifier'));
        $this->assertArrayNotHasKey('client_secret', $query);
    }

    #[Test]
    public function un_usuario_autorizado_inicia_sesion_y_se_vincula_su_identidad(): void
    {
        $user = User::factory()->create(['email' => 'laura.gomez@empresa.test', 'name' => 'Pendiente']);

        $this->returnFromMicrosoft($this->idToken())
            ->assertRedirect('http://localhost:5173/app');

        $this->assertAuthenticatedAs($user->fresh(), 'web');
        $user->refresh();
        $this->assertSame('0f0f0f0f-1111-2222-3333-444444444444', $user->microsoft_oid);
        $this->assertSame($this->tenantId, $user->microsoft_tenant_id);
        $this->assertSame('Laura Gómez', $user->name);
        $this->assertNotNull($user->last_login_at);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login')->where('actor_id', $user->id)->exists());
    }

    #[Test]
    public function rechaza_cuentas_no_autorizadas_previamente(): void
    {
        $this->returnFromMicrosoft($this->idToken())
            ->assertRedirect('http://localhost:5173/login?error=not_authorized');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login_rejected')->exists());
    }

    #[Test]
    public function con_autoaprovisionamiento_registra_la_cuenta_sin_roles(): void
    {
        config(['nova.auth.auto_provision_users' => true]);

        $this->returnFromMicrosoft($this->idToken())->assertRedirect('http://localhost:5173/app');

        $user = User::query()->where('email', 'laura.gomez@empresa.test')->firstOrFail();
        $this->assertCount(0, $user->roles);
        $this->assertSame([], $user->permissionCodes()->all());
    }

    #[Test]
    public function rechaza_cuentas_desactivadas(): void
    {
        User::factory()->inactive()->create(['email' => 'laura.gomez@empresa.test']);

        $this->returnFromMicrosoft($this->idToken())
            ->assertRedirect('http://localhost:5173/login?error=account_inactive');
        $this->assertGuest('web');
    }

    #[Test]
    public function rechaza_cuentas_eliminadas_aunque_el_registro_automatico_este_activo(): void
    {
        config(['nova.auth.auto_provision_users' => true]);
        $user = User::factory()->create(['email' => 'laura.gomez@empresa.test']);
        $user->delete();

        $this->returnFromMicrosoft($this->idToken())
            ->assertRedirect('http://localhost:5173/login?error=not_authorized');

        $this->assertGuest('web');
        $this->assertSame(1, User::withTrashed()->count());
    }

    #[Test]
    public function rechaza_un_correo_vinculado_a_otra_identidad_de_microsoft(): void
    {
        User::factory()->create([
            'email' => 'laura.gomez@empresa.test',
            'microsoft_oid' => '99999999-9999-9999-9999-999999999999',
        ]);

        $this->returnFromMicrosoft($this->idToken())
            ->assertRedirect('http://localhost:5173/login?error=account_mismatch');
        $this->assertGuest('web');
    }

    #[Test]
    public function rechaza_dominios_no_permitidos(): void
    {
        config(['nova.auth.allowed_email_domains' => ['otraempresa.test']]);
        User::factory()->create(['email' => 'laura.gomez@empresa.test']);

        $this->returnFromMicrosoft($this->idToken())
            ->assertRedirect('http://localhost:5173/login?error=not_authorized');
    }

    #[Test]
    public function rechaza_tokens_de_otro_tenant_aunque_el_correo_este_autorizado(): void
    {
        User::factory()->create(['email' => 'laura.gomez@empresa.test']);

        $this->returnFromMicrosoft($this->idToken(['tid' => '99999999-2222-3333-4444-555555555555']))
            ->assertRedirect('http://localhost:5173/login?error=tenant_mismatch');
        $this->assertGuest('web');
    }

    #[Test]
    public function rechaza_un_state_invalido(): void
    {
        User::factory()->create(['email' => 'laura.gomez@empresa.test']);
        $this->fakeTokenEndpoint($this->idToken());

        $this->withSession(['state' => 'state-123', 'entra_nonce' => 'expected-nonce'])
            ->get(self::CALLBACK.'?code=auth-code&state=otro-state')
            ->assertRedirect('http://localhost:5173/login?error=invalid_state');

        $this->assertGuest('web');
    }

    #[Test]
    public function rechaza_un_id_token_reutilizado_con_otro_nonce(): void
    {
        User::factory()->create(['email' => 'laura.gomez@empresa.test']);

        $this->returnFromMicrosoft($this->idToken(), nonce: 'nonce-de-otra-sesion')
            ->assertRedirect('http://localhost:5173/login?error=invalid_token');
        $this->assertGuest('web');
    }

    #[Test]
    public function informa_cuando_el_usuario_cancela_en_microsoft(): void
    {
        $this->get(self::CALLBACK.'?error=access_denied&error_description=cancelado')
            ->assertRedirect('http://localhost:5173/login?error=access_denied');
    }

    #[Test]
    public function cierra_la_sesion(): void
    {
        $user = $this->superAdmin();

        $this->actingAs($user, 'web')
            ->withHeader('Referer', 'http://localhost:5173/app')
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertGuest('web');
        $this->assertTrue(AuditLog::query()->where('action', 'auth.logout')->exists());
    }
}
