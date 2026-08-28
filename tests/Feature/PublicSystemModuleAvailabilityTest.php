<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicSystemModuleAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('directory.public_access', true);
        Route::prefix('public-module-test')->group(base_path('routes/api.php'));
    }

    public function test_real_public_api_routes_still_honor_server_module_state(): void
    {
        foreach ([
            ['MODULO_DIRECTORIO', 'get', '/public-module-test/v1/clientes'],
            ['MODULO_FLOTA', 'get', '/public-module-test/v1/camiones'],
            ['MODULO_DESPACHO_MAYORISTA', 'get', '/public-module-test/v1/operacion/catalogo'],
            ['MODULO_DESPACHO_MINORISTA_1', 'get', '/public-module-test/v1/despacho-minorista/catalogo'],
            ['MODULO_DESPACHO_MINORISTA_2', 'get', '/public-module-test/v1/despacho-minorista-2/catalogo'],
            ['MODULO_RESUMEN_JORNADA', 'get', '/public-module-test/v1/operacion/tickets-dia'],
            ['MODULO_GESTION_PESADAS', 'get', '/public-module-test/v1/operacion/gestion-pesadas'],
            ['MODULO_JORNADA_PROVEEDORES', 'put', '/public-module-test/v1/operacion/jornada'],
            ['MODULO_CONTROL_JAVAS', 'get', '/public-module-test/v1/control-javas'],
        ] as [$module, $method, $url]) {
            $this->setModule($module, false);

            $response = $method === 'put'
                ? $this->putJson($url, [])
                : $this->getJson($url);

            $response
                ->assertForbidden()
                ->assertJsonPath('code', 'MODULE_DISABLED');

            $this->setModule($module, true);
        }
    }

    public function test_real_public_shared_routes_are_blocked_only_when_every_owner_is_disabled(): void
    {
        $this->setModule('MODULO_DESPACHO_MAYORISTA', false);
        $this->setModule('MODULO_JORNADA_PROVEEDORES', false);

        $this->getJson('/public-module-test/v1/operacion/jornada')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');

        $this->setModule('MODULO_DESPACHO_MINORISTA_1', false);
        $this->setModule('MODULO_DESPACHO_MINORISTA_2', false);

        $this->getJson('/public-module-test/v1/operacion/precios-jornada')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_public_retail_price_read_remains_available_when_price_module_is_disabled(): void
    {
        $this->setModule('MODULO_PRECIOS_JORNADA', false);

        $this->getJson('/public-module-test/v1/operacion/precios-jornada')
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
        $retailPriceRead = $this->getJson('/public-module-test/v1/despacho-minorista-2/precios-jornada');
        $this->assertNotSame(403, $retailPriceRead->getStatusCode());
        $this->assertNotSame('MODULE_DISABLED', $retailPriceRead->json('code'));
        $this->putJson('/public-module-test/v1/operacion/precios-jornada', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
        $this->putJson('/public-module-test/v1/operacion/precios-jornada/mensaje-ticket', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
        $this->putJson('/public-module-test/v1/operacion/precios-jornada/titulo-ticket', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    private function setModule(string $module, bool $enabled): void
    {
        $this->artisan('modulos', [
            'accion' => $enabled ? 'activar' : 'desactivar',
            'modulo' => $module,
        ])->assertSuccessful();
    }
}
