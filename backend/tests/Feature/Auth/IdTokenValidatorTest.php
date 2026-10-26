<?php

namespace Tests\Feature\Auth;

use App\Auth\EntraId\AuthenticationFailed;
use App\Auth\EntraId\IdTokenValidator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EntraIdTokens;
use Tests\TestCase;

class IdTokenValidatorTest extends TestCase
{
    use EntraIdTokens;

    private bool $jwksFaked = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEntraId();
    }

    /** Http::fake acumula stubs (gana el primero), por eso se define por prueba. */
    private function fakeJwks(mixed $sequence = null): void
    {
        Http::fake([$this->jwksUrl() => $sequence ?? Http::response($this->jwks())]);
        $this->jwksFaked = true;
    }

    private function validator(): IdTokenValidator
    {
        if (! $this->jwksFaked) {
            $this->fakeJwks();
        }

        return $this->app->make(IdTokenValidator::class);
    }

    private function assertRejected(string $token, string $reason, string $nonce = 'expected-nonce'): void
    {
        try {
            $this->validator()->validate($token, $nonce);
            $this->fail('El token debió ser rechazado.');
        } catch (AuthenticationFailed $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    #[Test]
    public function acepta_un_id_token_valido_del_tenant_configurado(): void
    {
        $identity = $this->validator()->validate($this->idToken(), 'expected-nonce');

        $this->assertSame('0f0f0f0f-1111-2222-3333-444444444444', $identity->objectId);
        $this->assertSame($this->tenantId, $identity->tenantId);
        $this->assertSame('laura.gomez@empresa.test', $identity->email);
        $this->assertSame('Laura Gómez', $identity->name);
    }

    #[Test]
    public function rechaza_tokens_emitidos_para_otra_aplicacion(): void
    {
        $this->assertRejected($this->idToken(['aud' => 'otra-aplicacion']), AuthenticationFailed::INVALID_TOKEN);
    }

    #[Test]
    public function rechaza_cuentas_de_otro_tenant(): void
    {
        $this->assertRejected(
            $this->idToken(['tid' => '99999999-2222-3333-4444-555555555555']),
            AuthenticationFailed::TENANT_MISMATCH,
        );
    }

    #[Test]
    public function rechaza_un_emisor_distinto(): void
    {
        $this->assertRejected($this->idToken(['iss' => 'https://sts.example.com/']), AuthenticationFailed::INVALID_TOKEN);
    }

    #[Test]
    public function rechaza_tokens_expirados(): void
    {
        $this->assertRejected(
            $this->idToken(['iat' => time() - 7200, 'nbf' => time() - 7200, 'exp' => time() - 3600]),
            AuthenticationFailed::INVALID_TOKEN,
        );
    }

    #[Test]
    public function rechaza_un_nonce_distinto_o_ausente(): void
    {
        $this->assertRejected($this->idToken(), AuthenticationFailed::INVALID_TOKEN, 'otro-nonce');
        $this->assertRejected($this->idToken(['nonce' => null]), AuthenticationFailed::INVALID_TOKEN);
    }

    #[Test]
    public function rechaza_firmas_que_no_corresponden_a_las_claves_del_tenant(): void
    {
        $attackerKey = $this->makeRsaKey('test-kid-1');

        $this->assertRejected($this->idToken([], $attackerKey), AuthenticationFailed::INVALID_TOKEN);
    }

    #[Test]
    public function rechaza_tokens_manipulados(): void
    {
        [$header, $payload, $signature] = explode('.', $this->idToken());
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['preferred_username'] = 'admin@empresa.test';
        $tampered = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

        $this->assertRejected("{$header}.{$tampered}.{$signature}", AuthenticationFailed::INVALID_TOKEN);
    }

    #[Test]
    public function recarga_las_claves_cuando_microsoft_las_rota(): void
    {
        $rotated = $this->makeRsaKey('rotated-kid');

        $this->fakeJwks(Http::sequence()
            ->push($this->jwks())           // claves en caché (sin la nueva)
            ->push($this->jwks($rotated))); // claves publicadas tras la rotación

        $this->validator()->validate($this->idToken(), 'expected-nonce'); // llena la caché
        $identity = $this->validator()->validate($this->idToken([], $rotated), 'expected-nonce');

        $this->assertSame('laura.gomez@empresa.test', $identity->email);
        Http::assertSentCount(2);
    }
}
