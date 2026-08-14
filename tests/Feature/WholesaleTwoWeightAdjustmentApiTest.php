<?php

namespace Tests\Feature;

use App\Models\AjustePesoMayoristaDos;
use App\Models\AjustePesoMinorista;
use App\Models\User;
use App\Support\WholesaleTwoChickenVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class WholesaleTwoWeightAdjustmentApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('directory.public_access', false);
        $this->user = User::factory()->create();
        $this->grantModules(
            $this->user,
            ['MODULO_DESPACHO_MAYORISTA_2'],
            'CONFIGURA_MERMAS_MAYORISTA_2',
            'Configura mermas Mayorista 2',
        );
        $this->branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->update(['sucursal_id' => $this->branchId]);

        Sanctum::actingAs($this->user, ['api']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_configuration_creates_seven_independent_defaults_without_touching_timestamps_on_read(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $this->getJson('/api/v1/despacho-mayorista-2/configuracion-mermas')
            ->assertOk()
            ->assertJsonCount(7, 'data.adjustments')
            ->assertJsonPath('data.adjustments.0.code', WholesaleTwoChickenVariant::MALE)
            ->assertJsonPath('data.adjustments.0.additional_grams', 0)
            ->assertJsonPath('data.adjustments.0.configurable', true)
            ->assertJsonPath('data.adjustments.6.code', WholesaleTwoChickenVariant::PROCESSED)
            ->assertJsonPath('data.adjustments.6.additional_grams', 0)
            ->assertJsonPath('data.adjustments.6.configurable', false);

        $timestamps = DB::table('ajustes_peso_mayorista_2')
            ->where('empresa_id', $this->user->empresa_id)
            ->orderBy('codigo')
            ->pluck('updated_at', 'codigo')
            ->all();

        Carbon::setTestNow('2026-08-14 11:00:00');
        $this->getJson('/api/v1/despacho-mayorista-2/configuracion-mermas')
            ->assertOk();

        $this->assertSame(
            $timestamps,
            DB::table('ajustes_peso_mayorista_2')
                ->where('empresa_id', $this->user->empresa_id)
                ->orderBy('codigo')
                ->pluck('updated_at', 'codigo')
                ->all()
        );
        $this->assertDatabaseCount('ajustes_peso_mayorista_2', 7);
    }

    public function test_configuration_updates_exactly_six_variants_and_keeps_processed_at_zero(): void
    {
        DB::table('ajustes_peso_minorista')->insert([
            'empresa_id' => $this->user->empresa_id,
            'estacion' => 1,
            'codigo' => AjustePesoMinorista::MALE_CLOSED,
            'nombre' => 'Macho cerrado minorista',
            'sexo' => 'MACHO',
            'presentacion' => 'CERRADO',
            'gramos_adicionales' => 777,
            'predeterminado' => true,
            'estado' => AjustePesoMinorista::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = $this->configurationPayload();
        $payload['adjustments'][] = [
            'code' => WholesaleTwoChickenVariant::PROCESSED,
            'additional_grams' => 0,
        ];

        $this->putJson(
            '/api/v1/despacho-mayorista-2/configuracion-mermas',
            $payload
        )->assertOk()
            ->assertJsonCount(7, 'data.adjustments')
            ->assertJsonPath('data.adjustments.6.additional_grams', 0)
            ->assertJsonPath('data.adjustments.6.configurable', false);

        foreach ($payload['adjustments'] as $adjustment) {
            $this->assertDatabaseHas('ajustes_peso_mayorista_2', [
                'empresa_id' => $this->user->empresa_id,
                'codigo' => $adjustment['code'],
                'gramos_adicionales' => $adjustment['additional_grams'],
            ]);
        }
        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'estacion' => 1,
            'codigo' => AjustePesoMinorista::MALE_CLOSED,
            'gramos_adicionales' => 777,
        ]);
    }

    public function test_configuration_rejects_missing_variants_and_non_zero_processed_adjustment(): void
    {
        $missing = $this->configurationPayload();
        array_pop($missing['adjustments']);

        $this->putJson('/api/v1/despacho-mayorista-2/configuracion-mermas', $missing)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('adjustments');

        $processed = $this->configurationPayload();
        $processed['adjustments'][] = [
            'code' => WholesaleTwoChickenVariant::PROCESSED,
            'additional_grams' => 1,
        ];

        $this->putJson('/api/v1/despacho-mayorista-2/configuracion-mermas', $processed)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('adjustments');

        $this->assertDatabaseCount('ajustes_peso_mayorista_2', 0);
    }

    public function test_configuration_routes_require_wholesale_two_access(): void
    {
        $unauthorized = User::factory()->create([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
        ]);
        Sanctum::actingAs($unauthorized, ['api']);

        $this->getJson('/api/v1/despacho-mayorista-2/configuracion-mermas')
            ->assertForbidden();
        $this->putJson(
            '/api/v1/despacho-mayorista-2/configuracion-mermas',
            $this->configurationPayload()
        )->assertForbidden();
    }

    /** @return array{adjustments: list<array{code: string, additional_grams: int}>} */
    private function configurationPayload(): array
    {
        return [
            'adjustments' => collect(AjustePesoMayoristaDos::configurableCodes())
                ->values()
                ->map(fn (string $code, int $index): array => [
                    'code' => $code,
                    'additional_grams' => ($index + 1) * 25,
                ])
                ->all(),
        ];
    }
}
