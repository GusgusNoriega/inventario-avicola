<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class WholesaleTwoIsolationTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_wholesale_two_web_views_use_their_own_assets_and_permission(): void
    {
        $wholesaleTwoUser = User::factory()->create();
        $this->grantModules(
            $wholesaleTwoUser,
            ['MODULO_DESPACHO_MAYORISTA_2'],
            'MAYORISTA_2',
            'Despacho mayorista 2',
        );

        $this->actingAs($wholesaleTwoUser);

        $this->get('/despacho-mayorista-2')
            ->assertOk()
            ->assertSee(route('despacho-mayorista-2.pantalla-cliente'), false)
            ->assertSee(asset('css/despacho-mayorista-2.css'), false)
            ->assertSee(asset('js/despacho-mayorista-2.js'), false)
            ->assertDontSee(asset('css/style.css'), false)
            ->assertDontSee(asset('js/app.js'), false);

        $this->get('/despacho-mayorista-2/pantalla-cliente')
            ->assertOk()
            ->assertSee(asset('css/pantalla-cliente-mayorista-2.css'), false)
            ->assertSee(asset('js/pantalla-cliente-mayorista-2.js'), false)
            ->assertDontSee(asset('css/pantalla-cliente.css'), false)
            ->assertDontSee(asset('js/pantalla-cliente.js'), false);

        $this->get('/operacion')->assertForbidden();
        $this->get('/operacion/pantalla-cliente')->assertForbidden();

        $wholesaleOneUser = User::factory()->create();
        $this->grantModules(
            $wholesaleOneUser,
            ['MODULO_DESPACHO_MAYORISTA'],
            'MAYORISTA_1',
            'Despacho mayorista 1',
        );

        $this->actingAs($wholesaleOneUser);

        $this->get('/despacho-mayorista-2')->assertForbidden();
        $this->get('/despacho-mayorista-2/pantalla-cliente')->assertForbidden();
    }

    public function test_wholesale_two_api_is_available_only_through_its_own_module_and_prefix(): void
    {
        config()->set('directory.public_access', false);

        $wholesaleTwoUser = User::factory()->create();
        $this->createBranchFor($wholesaleTwoUser, 'MAYORISTA_2');
        $this->grantModules(
            $wholesaleTwoUser,
            ['MODULO_DESPACHO_MAYORISTA_2'],
            'API_MAYORISTA_2',
            'API despacho mayorista 2',
        );
        Sanctum::actingAs($wholesaleTwoUser, ['api']);

        foreach (['catalogo', 'clientes', 'proveedores', 'jornada'] as $path) {
            $this->getJson("/api/v1/despacho-mayorista-2/{$path}")->assertOk();
        }
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', [])->assertUnprocessable();

        foreach (['catalogo', 'clientes', 'proveedores', 'jornada'] as $path) {
            $this->getJson("/api/v1/operacion/{$path}")->assertForbidden();
        }
        $this->postJson('/api/v1/operacion/tickets', [])->assertForbidden();

        $wholesaleOneUser = User::factory()->create();
        $this->createBranchFor($wholesaleOneUser, 'MAYORISTA_1');
        $this->grantModules(
            $wholesaleOneUser,
            ['MODULO_DESPACHO_MAYORISTA'],
            'API_MAYORISTA_1',
            'API despacho mayorista 1',
        );
        Sanctum::actingAs($wholesaleOneUser, ['api']);

        foreach (['catalogo', 'clientes', 'proveedores', 'jornada'] as $path) {
            $this->getJson("/api/v1/despacho-mayorista-2/{$path}")->assertForbidden();
        }
        $this->postJson('/api/v1/despacho-mayorista-2/tickets', [])->assertForbidden();
    }

    public function test_wholesale_two_uses_independent_browser_storage_and_customer_display_channels(): void
    {
        $wholesaleOne = (string) file_get_contents(public_path('js/app.js'));
        $wholesaleTwo = (string) file_get_contents(public_path('js/despacho-mayorista-2.js'));
        $displayOne = (string) file_get_contents(public_path('js/pantalla-cliente.js'));
        $displayTwo = (string) file_get_contents(public_path('js/pantalla-cliente-mayorista-2.js'));

        $expectedMainConstants = [
            'STORAGE_KEY' => 'sistema-pollos-despacho-mayorista-2-state-v1',
            'STORAGE_KEY_PREFIX' => 'sistema-pollos-despacho-mayorista-2-state-v2',
            'STORAGE_MIGRATION_KEY' => 'sistema-pollos-despacho-mayorista-2-state-v2-migrated-branch',
            'CUSTOMER_DISPLAY_CHANNEL_NAME' => 'sistema-pollos-pantalla-cliente-mayorista-2-v1',
            'CUSTOMER_DISPLAY_STORAGE_KEY' => 'sistema-pollos-pantalla-cliente-mayorista-2-estado-v1',
            'CUSTOMER_DISPLAY_PRODUCER_SESSION_KEY' => 'sistema-pollos-pantalla-cliente-mayorista-2-productor-v1',
            'CUSTOMER_DISPLAY_PRODUCER_INSTANCE_SESSION_KEY' => 'sistema-pollos-pantalla-cliente-mayorista-2-instancia-v1',
            'PEOPLE_STORAGE_KEY' => 'sistema-pollos-despacho-mayorista-2-personas-v1',
            'FONT_SIZE_STORAGE_KEY' => 'sistema-pollos-despacho-mayorista-2-font-size-v1',
            'CUSTOM_FONT_SIZE_STORAGE_KEY' => 'sistema-pollos-despacho-mayorista-2-custom-font-sizes-v1',
            'VIEW_ZOOM_STORAGE_KEY' => 'sistema-pollos-despacho-mayorista-2-view-zoom-v1',
        ];

        foreach ($expectedMainConstants as $constant => $expectedValue) {
            $actualValue = $this->javascriptStringConstant($wholesaleTwo, $constant);

            $this->assertSame($expectedValue, $actualValue);
            $this->assertNotSame(
                $this->javascriptStringConstant($wholesaleOne, $constant),
                $actualValue,
                "{$constant} no debe compartirse entre las dos vistas mayoristas.",
            );
        }

        $this->assertSame(
            'sistema-pollos-pantalla-cliente-mayorista-2-v1',
            $this->javascriptStringConstant($displayTwo, 'CHANNEL_NAME'),
        );
        $this->assertSame(
            'sistema-pollos-pantalla-cliente-mayorista-2-estado-v1',
            $this->javascriptStringConstant($displayTwo, 'STORAGE_KEY_PREFIX'),
        );
        $this->assertNotSame(
            $this->javascriptStringConstant($displayOne, 'CHANNEL_NAME'),
            $this->javascriptStringConstant($displayTwo, 'CHANNEL_NAME'),
        );
        $this->assertNotSame(
            $this->javascriptStringConstant($displayOne, 'STORAGE_KEY_PREFIX'),
            $this->javascriptStringConstant($displayTwo, 'STORAGE_KEY_PREFIX'),
        );

        foreach (['catalogo', 'jornada', 'tickets'] as $path) {
            $this->assertStringContainsString("/despacho-mayorista-2/{$path}", $wholesaleTwo);
        }
        $this->assertStringContainsString('/despacho-mayorista-2/${type}?per_page=100', $wholesaleTwo);
        $this->assertStringNotContainsString('apiRequest("/operacion/', $wholesaleTwo);
    }

    private function createBranchFor(User $user, string $code): int
    {
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => $code,
            'nombre' => "Sucursal {$code}",
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->update(['sucursal_id' => $branchId]);

        return $branchId;
    }

    private function javascriptStringConstant(string $source, string $constant): string
    {
        $matched = preg_match(
            '/const\s+'.preg_quote($constant, '/').'\s*=\s*"([^"]+)"\s*;/',
            $source,
            $matches,
        );

        $this->assertSame(1, $matched, "No se encontró la constante JavaScript {$constant}.");

        return $matches[1];
    }
}
