<?php

namespace Tests\Feature;

use App\Models\AjustePesoMinorista;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetailDispatchApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $clientId;

    private int $typeId;

    private int $priceHistoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
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

        $permissions = collect(['DESPACHOS_VER', 'DESPACHOS_CREAR'])
            ->map(fn (string $code) => Permission::query()->create([
                'codigo' => $code,
                'descripcion' => $code,
            ]));
        $role = Role::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'OPERADOR',
            'nombre' => 'Operador',
        ]);
        $role->permissions()->attach($permissions);
        $this->user->roles()->attach($role);
        Sanctum::actingAs($this->user, ['api']);

        $this->typeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => TipoPollo::CHICKEN_LIVE,
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'RUC',
            'numero_documento' => '20111111111',
            'nombre_razon_social' => 'Cliente minorista',
            'direccion' => 'Mercado principal',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $this->clientId,
            'rol' => 'CLIENTE',
            'created_at' => now(),
        ]);
        $listId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $this->clientId,
            'codigo' => 'CLIENTE-MINORISTA',
            'nombre' => 'Precios cliente minorista',
            'operacion' => 'VENTA',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->priceHistoryId = DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $listId,
            'tipo_pollo_id' => $this->typeId,
            'precio_kg' => 8.5,
            'vigente_desde' => now()->subMinute(),
            'vigente_hasta' => null,
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);
    }

    public function test_catalog_returns_prices_adjustments_and_the_exclusive_retail_scale(): void
    {
        $this->getJson('/api/v1/despacho-minorista/catalogo')
            ->assertOk()
            ->assertJsonPath('data.clients.0.id', $this->clientId)
            ->assertJsonPath('data.clients.0.prices.POLLO_VIVO.price_kg', 8.5)
            ->assertJsonPath('data.clients.0.prices.POLLO_VIVO.source', 'CLIENTE')
            ->assertJsonPath('data.chicken_types.0.code', TipoPollo::CHICKEN_LIVE)
            ->assertJsonPath('data.tray_types.0.code', 'BANDEJA_ESTANDAR')
            ->assertJsonPath('data.tray_types.0.bird_capacity', 5)
            ->assertJsonPath('data.adjustments.0.code', AjustePesoMinorista::MALE_CLOSED)
            ->assertJsonPath('data.adjustments.0.is_default', true)
            ->assertJsonCount(4, 'data.adjustments')
            ->assertJsonPath('data.scale.code', 'BALANZA_MINORISTA')
            ->assertJsonPath('data.scale.connection_mode', 'SERIAL')
            ->assertJsonPath('data.scale.configuration.baudRate', 9600)
            ->assertJsonMissingPath('data.cage_types');

        $this->assertDatabaseHas('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => 'BALANZA_MINORISTA',
        ]);
    }

    public function test_retail_configuration_updates_only_the_retail_scale_and_company_adjustments(): void
    {
        DB::table('balanzas')->insert([
            'sucursal_id' => $this->branchId,
            'codigo' => 'BALANZA_1',
            'nombre' => 'Balanza mayorista',
            'modo_conexion' => 'BLUETOOTH',
            'dispositivo' => 'Mayorista',
            'configuracion' => null,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson('/api/v1/despacho-minorista/configuracion', [
            'scale' => [
                'connection_mode' => 'serial',
                'device' => 'COM7',
                'configuration' => [
                    'baudRate' => 19200,
                    'dataBits' => 7,
                    'stopBits' => 2,
                    'parity' => 'EVEN',
                    'flowControl' => 'HARDWARE',
                    'profileId' => null,
                    'profileLabel' => null,
                ],
            ],
            'default_adjustment_code' => AjustePesoMinorista::FEMALE_OPEN,
            'adjustments' => [
                ['code' => AjustePesoMinorista::MALE_CLOSED, 'additional_grams' => 120],
                ['code' => AjustePesoMinorista::FEMALE_OPEN, 'additional_grams' => 275],
            ],
        ])->assertOk()
            ->assertJsonPath('data.scale.code', 'BALANZA_MINORISTA')
            ->assertJsonPath('data.scale.device', 'COM7')
            ->assertJsonPath('data.scale.configuration.baudRate', 19200)
            ->assertJsonPath('data.scale.configuration.parity', 'even');

        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'codigo' => AjustePesoMinorista::FEMALE_OPEN,
            'gramos_adicionales' => 275,
            'predeterminado' => true,
        ]);
        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'codigo' => AjustePesoMinorista::MALE_CLOSED,
            'gramos_adicionales' => 120,
            'predeterminado' => false,
        ]);
        $this->assertDatabaseHas('balanzas', [
            'codigo' => 'BALANZA_1',
            'modo_conexion' => 'BLUETOOTH',
            'dispositivo' => 'Mayorista',
        ]);
    }

    public function test_retail_dispatch_calculates_adjusted_weight_on_server_and_stores_snapshots(): void
    {
        $this->getJson('/api/v1/despacho-minorista/catalogo')->assertOk();
        DB::table('ajustes_peso_minorista')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('codigo', AjustePesoMinorista::FEMALE_CLOSED)
            ->update(['gramos_adicionales' => 250]);
        DB::table('tipos_bandeja')
            ->where('codigo', 'BANDEJA_ESTANDAR')
            ->update(['peso_kg' => 0.5]);
        $payload = $this->payload();
        $payload['weighings'][0]['adjustment_code'] = AjustePesoMinorista::FEMALE_CLOSED;

        $response = $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('already_registered', false)
            ->assertJsonPath('data.channel', TicketDespacho::CHANNEL_RETAIL)
            ->assertJsonPath('data.operation_type', TicketDespacho::OPERATION_DISPATCH)
            ->assertJsonPath('data.client.id', $this->clientId)
            ->assertJsonPath('data.totals.trays', 2)
            ->assertJsonPath('data.totals.birds', 10)
            ->assertJsonPath('data.totals.read_weight_kg', 12)
            ->assertJsonPath('data.totals.gross_weight_kg', 12.25)
            ->assertJsonPath('data.totals.net_weight_kg', 11.25)
            ->assertJsonPath('data.totals.amount', 95.63)
            ->assertJsonPath('data.weighings.0.chicken_sex', 'HEMBRA')
            ->assertJsonPath('data.weighings.0.presentation', 'CERRADA')
            ->assertJsonPath('data.weighings.0.adjustment.additional_grams', 250)
            ->assertJsonPath('data.weighings.0.weight_source', 'BALANZA_MINORISTA');

        $this->assertStringStartsWith('M-', $response->json('data.code'));
        $this->assertDatabaseHas('tickets_despacho', [
            'canal' => TicketDespacho::CHANNEL_RETAIL,
            'tipo_operacion' => TicketDespacho::OPERATION_DISPATCH,
            'cliente_destino_id' => $this->clientId,
            'vehiculo_entrega_id' => null,
            'conductor_entrega_id' => null,
        ]);
        $this->assertDatabaseHas('pesadas', [
            'tipo_java_id' => null,
            'aves_por_java' => null,
            'cantidad_javas' => null,
            'peso_java_kg_snapshot' => null,
            'sexo' => 'HEMBRA',
            'presentacion_pollo' => 'CERRADA',
            'ajuste_peso_gramos' => 250,
            'origen_peso' => 'BALANZA_MINORISTA',
            'aves_por_bandeja' => 5,
            'cantidad_bandejas' => 2,
            'cantidad_aves' => 10,
            'peso_bandeja_kg_snapshot' => 0.5,
            'peso_leido_kg' => 12,
            'peso_bruto_kg' => 12.25,
            'tara_total_kg' => 1,
            'peso_neto_kg' => 11.25,
        ]);
        $this->assertDatabaseCount('movimientos_javas', 0);
    }

    public function test_missing_adjustment_uses_the_company_default(): void
    {
        $payload = $this->payload();
        unset($payload['weighings'][0]['adjustment_code']);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.weighings.0.adjustment.code', AjustePesoMinorista::MALE_CLOSED)
            ->assertJsonPath('data.weighings.0.chicken_sex', 'MACHO')
            ->assertJsonPath('data.weighings.0.presentation', 'CERRADO');
    }

    public function test_manual_price_keeps_base_history_foreign_key_and_marks_its_origin(): void
    {
        $payload = $this->payload();
        $payload['price_overrides'] = [TipoPollo::CHICKEN_LIVE => 10.25];

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.prices.POLLO_VIVO.price_kg', 10.25)
            ->assertJsonPath('data.prices.POLLO_VIVO.source', 'MANUAL')
            ->assertJsonPath('data.prices.POLLO_VIVO.history_id', $this->priceHistoryId)
            ->assertJsonPath('data.weighings.0.price_origin', 'MANUAL')
            ->assertJsonPath('data.totals.amount', 123);

        $this->assertDatabaseHas('ticket_precios', [
            'tipo_pollo_id' => $this->typeId,
            'precio_historial_id' => $this->priceHistoryId,
            'precio_kg' => 10.25,
            'origen_precio' => 'MANUAL',
        ]);
    }

    public function test_return_amounts_are_serialized_with_negative_sign(): void
    {
        $payload = $this->payload();
        $payload['operation_type'] = TicketDespacho::OPERATION_RETURN;

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.operation_type', TicketDespacho::OPERATION_RETURN)
            ->assertJsonPath('data.weighings.0.amount', -102)
            ->assertJsonPath('data.totals.amount', -102);

        $this->assertDatabaseHas('tickets_despacho', [
            'canal' => TicketDespacho::CHANNEL_RETAIL,
            'tipo_operacion' => TicketDespacho::OPERATION_RETURN,
        ]);
    }

    public function test_retail_dispatch_requires_raw_weight_and_rejects_browser_gross_weight(): void
    {
        $payload = $this->payload();
        unset($payload['weighings'][0]['read_weight_kg']);
        $payload['weighings'][0]['gross_weight_kg'] = 999;

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'weighings.0',
                'weighings.0.read_weight_kg',
            ]);

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_retail_dispatch_requires_at_least_one_tray_and_its_exclusive_scale_source(): void
    {
        $payload = $this->payload();
        $payload['weighings'][0]['tray_count'] = 0;
        $payload['weighings'][0]['weight_source'] = 'BALANZA_1';

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'weighings.0.tray_count',
                'weighings.0.weight_source',
            ]);

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_repeating_the_same_retail_draft_is_idempotent(): void
    {
        $payload = $this->payload();

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)->assertCreated();
        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertOk()
            ->assertJsonPath('already_registered', true)
            ->assertJsonPath('data.channel', TicketDespacho::CHANNEL_RETAIL);

        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseCount('pesadas', 1);
    }

    public function test_retail_idempotency_rejects_a_uuid_already_used_by_wholesale(): void
    {
        $payload = $this->payload();
        $journeyId = DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $this->branchId,
            'fecha_operativa' => today('America/Lima')->toDateString(),
            'estado' => 'ABIERTA',
            'abierta_por' => $this->user->id,
            'inicio_at' => now()->subHour(),
            'cierre_programado_at' => now()->addHours(10),
        ]);
        DB::table('tickets_despacho')->insert([
            'jornada_id' => $journeyId,
            'codigo' => 'D-OTRO-CANAL',
            'referencia_externa' => $payload['draft_id'],
            'canal' => TicketDespacho::CHANNEL_WHOLESALE,
            'tipo_operacion' => TicketDespacho::OPERATION_DISPATCH,
            'cliente_destino_id' => $this->clientId,
            'estado' => TicketDespacho::STATUS_CLOSED,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft_id');

        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseCount('pesadas', 0);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'draft_id' => (string) Str::uuid(),
            'client_id' => $this->clientId,
            'operation_type' => TicketDespacho::OPERATION_DISPATCH,
            'weighings' => [[
                'local_id' => 1,
                'chicken_type_code' => TipoPollo::CHICKEN_LIVE,
                'adjustment_code' => AjustePesoMinorista::MALE_CLOSED,
                'tray_type_code' => 'BANDEJA_ESTANDAR',
                'weight_source' => 'BALANZA_MINORISTA',
                'birds_per_tray' => 5,
                'tray_count' => 2,
                'read_weight_kg' => 12,
                'weighed_at' => now('America/Lima')->subMinute()->toIso8601String(),
            ]],
        ];
    }
}
