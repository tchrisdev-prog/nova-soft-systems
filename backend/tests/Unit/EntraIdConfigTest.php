<?php

namespace Tests\Unit;

use App\Auth\EntraId\EntraIdConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EntraIdConfigTest extends TestCase
{
    private function config(array $overrides = []): EntraIdConfig
    {
        $values = array_merge([
            'tenantId' => '11111111-2222-3333-4444-555555555555',
            'clientId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'clientSecret' => 'secret',
            'redirectUri' => 'https://erp.example.com/auth/microsoft/callback',
            'authority' => 'https://login.microsoftonline.com',
            'jwksCacheTtl' => 3600,
            'clockLeeway' => 60,
        ], $overrides);

        return new EntraIdConfig(...$values);
    }

    #[Test]
    public function una_configuracion_completa_es_valida(): void
    {
        $config = $this->config();

        $this->assertTrue($config->isConfigured());
        $this->assertSame('https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/v2.0', $config->expectedIssuer());
        $this->assertSame('https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/discovery/v2.0/keys', $config->jwksUrl());
    }

    #[Test]
    public function exige_un_tenant_concreto_y_no_common_ni_organizations(): void
    {
        $this->assertFalse($this->config(['tenantId' => 'common'])->isConfigured());
        $this->assertFalse($this->config(['tenantId' => 'organizations'])->isConfigured());
        $this->assertFalse($this->config(['tenantId' => null])->isConfigured());
    }

    #[Test]
    public function detecta_valores_faltantes_o_invalidos(): void
    {
        $problems = $this->config(['clientId' => 'no-es-guid', 'clientSecret' => null, 'redirectUri' => '/relativa'])->problems();

        $this->assertCount(3, $problems);
    }
}
