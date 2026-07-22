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

    private int $deliveryVehicleId;

    private int $deliveryDriverId;

    private int $cashAccountId;

    private int $cashMethodId;

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

        $financialEntityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'PROPIA',
            'tipo_documento' => 'RUC',
            'numero_documento' => '20999999991',
            'razon_social' => 'Caja minorista de prueba',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cashAccountId = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $financialEntityId,
            'tipo' => 'CAJA',
            'alias' => 'Caja principal',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cashMethodId = (int) DB::table('metodos_pago')
            ->where('codigo', 'EFECTIVO')
            ->value('id');

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
            'codigo' => TipoPollo::CHICKEN_DRESSED,
            'nombre' => 'Pollo pelado',
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
        $this->deliveryVehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'MIN-001',
            'marca' => 'Toyota',
            'modelo' => 'Dyna',
            'descripcion' => 'Camion minorista',
            'es_propio' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->deliveryDriverId = DB::table('conductores')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'nombre_completo' => 'CHOFER MINORISTA',
            'tipo_documento' => 'CC',
            'numero_documento' => '123456',
            'telefono' => '3001234567',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_catalog_returns_prices_adjustments_and_the_exclusive_retail_scale(): void
    {
        DB::table('tipos_pollo')->insert([
            [
                'codigo' => TipoPollo::CHICKEN_LIVE,
                'nombre' => 'Pollo vivo',
                'permite_despacho' => true,
                'estado' => TipoPollo::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => TipoPollo::CHICKEN_PROCESSED,
                'nombre' => 'Pollo beneficiado',
                'permite_despacho' => true,
                'estado' => TipoPollo::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->getJson('/api/v1/despacho-minorista/catalogo')
            ->assertOk()
            ->assertJsonPath('data.clients.0.id', $this->clientId)
            ->assertJsonPath('data.clients.0.prices.POLLO_PELADO.price_kg', 8.5)
            ->assertJsonPath('data.clients.0.prices.POLLO_PELADO.source', 'CLIENTE')
            ->assertJsonPath('data.delivery_trucks.0.id', $this->deliveryVehicleId)
            ->assertJsonPath('data.delivery_trucks.0.plate', 'MIN-001')
            ->assertJsonPath('data.delivery_drivers.0.id', $this->deliveryDriverId)
            ->assertJsonPath('data.delivery_drivers.0.name', 'CHOFER MINORISTA')
            ->assertJsonCount(2, 'data.chicken_types')
            ->assertJsonPath('data.chicken_types.0.code', TipoPollo::CHICKEN_DRESSED)
            ->assertJsonPath('data.chicken_types.1.code', TipoPollo::CHICKEN_PROCESSED)
            ->assertJsonMissing(['code' => TipoPollo::CHICKEN_LIVE])
            ->assertJsonPath('data.tray_types.0.code', 'BANDEJA_ESTANDAR')
            ->assertJsonPath('data.tray_types.0.weight_kg', 2.5)
            ->assertJsonPath('data.tray_types.0.bird_capacity', 5)
            ->assertJsonPath('data.adjustments.0.code', AjustePesoMinorista::MALE_CLOSED)
            ->assertJsonPath('data.adjustments.0.is_default', true)
            ->assertJsonCount(4, 'data.adjustments')
            ->assertJsonPath('data.scale.code', 'BALANZA_MINORISTA')
            ->assertJsonPath('data.scale.connection_mode', 'SERIAL')
            ->assertJsonPath('data.scale.configuration.baudRate', 9600)
            ->assertJsonPath('data.financial.methods.2.code', 'EFECTIVO')
            ->assertJsonPath('data.financial.own_accounts.0.id', $this->cashAccountId)
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
            'estacion' => 1,
            'codigo' => AjustePesoMinorista::FEMALE_OPEN,
            'gramos_adicionales' => 275,
            'predeterminado' => true,
        ]);
        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'estacion' => 1,
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

    public function test_retail_configuration_can_update_adjustments_without_resending_physical_scale_settings(): void
    {
        $this->getJson('/api/v1/despacho-minorista/catalogo')->assertOk();

        $this->putJson('/api/v1/despacho-minorista/configuracion', [
            'default_adjustment_code' => AjustePesoMinorista::MALE_OPEN,
            'adjustments' => [[
                'code' => AjustePesoMinorista::MALE_OPEN,
                'additional_grams' => 85,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.scale.code', 'BALANZA_MINORISTA')
            ->assertJsonPath('data.scale.connection_mode', 'SERIAL')
            ->assertJsonPath('data.scale.device', null);

        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'estacion' => 1,
            'codigo' => AjustePesoMinorista::MALE_OPEN,
            'gramos_adicionales' => 85,
            'predeterminado' => true,
        ]);
        $this->assertDatabaseHas('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => 'BALANZA_MINORISTA',
            'modo_conexion' => 'SERIAL',
            'dispositivo' => null,
        ]);
    }

    public function test_retail_configuration_rejects_an_empty_scale_object(): void
    {
        $this->putJson('/api/v1/despacho-minorista/configuracion', [
            'scale' => [],
            'default_adjustment_code' => AjustePesoMinorista::MALE_CLOSED,
            'adjustments' => [[
                'code' => AjustePesoMinorista::MALE_CLOSED,
                'additional_grams' => 0,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('scale');

        $this->assertDatabaseCount('balanzas', 0);
        $this->assertDatabaseCount('ajustes_peso_minorista', 0);
    }

    public function test_second_retail_station_shares_clients_and_prices_but_has_independent_adjustments_and_scale(): void
    {
        $this->getJson('/api/v1/despacho-minorista/catalogo')
            ->assertOk()
            ->assertJsonPath('data.scale.code', 'BALANZA_MINORISTA');

        $this->getJson('/api/v1/despacho-minorista-2/catalogo')
            ->assertOk()
            ->assertJsonPath('data.clients.0.id', $this->clientId)
            ->assertJsonPath('data.clients.0.prices.POLLO_PELADO.price_kg', 8.5)
            ->assertJsonPath('data.scale.code', 'BALANZA_MINORISTA_2');

        $this->putJson('/api/v1/despacho-minorista-2/configuracion', [
            'scale' => [
                'connection_mode' => 'BLUETOOTH',
                'device' => 'Balanza puesto 2',
                'configuration' => [
                    'baudRate' => 19200,
                    'dataBits' => 8,
                    'stopBits' => 1,
                    'parity' => 'none',
                    'flowControl' => 'none',
                    'profileId' => 'ble-station-2',
                    'profileLabel' => 'Puesto 2',
                ],
            ],
            'default_adjustment_code' => AjustePesoMinorista::MALE_CLOSED,
            'adjustments' => [[
                'code' => AjustePesoMinorista::MALE_CLOSED,
                'additional_grams' => 135,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.scale.code', 'BALANZA_MINORISTA_2')
            ->assertJsonPath('data.scale.device', 'Balanza puesto 2');

        $this->assertDatabaseHas('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => 'BALANZA_MINORISTA',
            'dispositivo' => null,
        ]);
        $this->assertDatabaseHas('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => 'BALANZA_MINORISTA_2',
            'dispositivo' => 'Balanza puesto 2',
        ]);

        $this->getJson('/api/v1/despacho-minorista/catalogo')
            ->assertOk()
            ->assertJsonPath('data.scale.device', null)
            ->assertJsonPath('data.adjustments.0.additional_grams', 0);
        $this->getJson('/api/v1/despacho-minorista-2/catalogo')
            ->assertOk()
            ->assertJsonPath('data.adjustments.0.additional_grams', 135);

        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'estacion' => 1,
            'codigo' => AjustePesoMinorista::MALE_CLOSED,
            'gramos_adicionales' => 0,
        ]);
        $this->assertDatabaseHas('ajustes_peso_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'estacion' => 2,
            'codigo' => AjustePesoMinorista::MALE_CLOSED,
            'gramos_adicionales' => 135,
        ]);

        $payload = $this->payload();
        $payload['weighings'][0]['weight_source'] = 'BALANZA_MINORISTA_2';
        $ticketId = $this->postJson('/api/v1/despacho-minorista-2/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.weighings.0.weight_source', 'BALANZA_MINORISTA_2')
            ->json('data.id');

        $this->assertTrue(
            DB::table('pesadas')
                ->join(
                    'ajustes_peso_minorista',
                    'ajustes_peso_minorista.id',
                    '=',
                    'pesadas.ajuste_peso_minorista_id'
                )
                ->where('pesadas.ticket_id', $ticketId)
                ->where('ajustes_peso_minorista.estacion', 2)
                ->exists()
        );
        $this->assertTrue(
            DB::table('pesadas')
                ->join('lecturas_balanza', 'lecturas_balanza.id', '=', 'pesadas.lectura_balanza_id')
                ->join('balanzas', 'balanzas.id', '=', 'lecturas_balanza.balanza_id')
                ->where('pesadas.ticket_id', $ticketId)
                ->where('balanzas.codigo', 'BALANZA_MINORISTA_2')
                ->exists()
        );
    }

    public function test_retail_dispatch_applies_adjustment_per_bird_and_stores_snapshots(): void
    {
        $this->getJson('/api/v1/despacho-minorista/catalogo')->assertOk();
        DB::table('ajustes_peso_minorista')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('estacion', 1)
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
            ->assertJsonPath('data.delivery.vehicle.id', $this->deliveryVehicleId)
            ->assertJsonPath('data.delivery.vehicle.plate', 'MIN-001')
            ->assertJsonPath('data.delivery.driver.id', $this->deliveryDriverId)
            ->assertJsonPath('data.delivery.driver.name', 'CHOFER MINORISTA')
            ->assertJsonPath('data.totals.trays', 2)
            ->assertJsonPath('data.totals.birds', 10)
            ->assertJsonPath('data.totals.read_weight_kg', 12)
            ->assertJsonPath('data.totals.gross_weight_kg', 14.5)
            ->assertJsonPath('data.totals.net_weight_kg', 13.5)
            ->assertJsonPath('data.totals.amount', 114.75)
            ->assertJsonPath('data.weighings.0.chicken_sex', 'HEMBRA')
            ->assertJsonPath('data.weighings.0.presentation', 'CERRADA')
            ->assertJsonPath('data.weighings.0.adjustment.additional_grams', 250)
            ->assertJsonPath('data.weighings.0.weight_source', 'BALANZA_MINORISTA');

        $this->assertStringStartsWith('M-', $response->json('data.code'));
        $this->assertDatabaseHas('tickets_despacho', [
            'canal' => TicketDespacho::CHANNEL_RETAIL,
            'tipo_operacion' => TicketDespacho::OPERATION_DISPATCH,
            'cliente_destino_id' => $this->clientId,
            'vehiculo_entrega_id' => $this->deliveryVehicleId,
            'conductor_entrega_id' => $this->deliveryDriverId,
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
            'peso_bruto_kg' => 14.5,
            'tara_total_kg' => 1,
            'peso_neto_kg' => 13.5,
        ]);
        $readingId = DB::table('pesadas')->where('numero', 1)->value('lectura_balanza_id');
        $this->assertNotNull($readingId);
        $this->assertDatabaseHas('lecturas_balanza', [
            'id' => $readingId,
            'peso_kg' => 12,
            'trama_cruda' => null,
            'modo_conexion' => null,
            'dispositivo' => null,
            'capturada_por' => $this->user->id,
        ]);
        $this->assertDatabaseHas('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => 'BALANZA_MINORISTA',
        ]);
        $this->assertDatabaseHas('movimientos_javas', [
            'ticket_despacho_id' => $response->json('data.id'),
            'cliente_id' => $this->clientId,
            'tipo' => 'DESPACHO',
            'cantidad' => 0,
            'cantidad_bandejas' => 2,
            'vehiculo_id' => $this->deliveryVehicleId,
            'conductor_id' => $this->deliveryDriverId,
        ]);
    }

    public function test_retail_scale_reading_metadata_is_audited_and_manual_weight_creates_no_reading(): void
    {
        $payload = $this->payload();
        $payload['weighings'][0]['scale_reading'] = [
            'raw_frame' => 'ST,GS,+00012.345kg',
            'connection_mode' => 'bluetooth',
            'device_name' => '  Balanza caja 1  ',
            'captured_at' => '2026-07-22T15:15:30Z',
        ];

        $ticketId = $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->json('data.id');

        $reading = DB::table('lecturas_balanza')
            ->join('balanzas', 'balanzas.id', '=', 'lecturas_balanza.balanza_id')
            ->where('balanzas.codigo', 'BALANZA_MINORISTA')
            ->select('lecturas_balanza.*')
            ->sole();
        $this->assertSame('ST,GS,+00012.345kg', $reading->trama_cruda);
        $this->assertSame('BLUETOOTH', $reading->modo_conexion);
        $this->assertSame('Balanza caja 1', $reading->dispositivo);
        $this->assertSame('2026-07-22 10:15:30', $reading->capturada_at);
        $this->assertSame($this->user->id, (int) $reading->capturada_por);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $ticketId,
            'lectura_balanza_id' => $reading->id,
            'origen_peso' => 'BALANZA_MINORISTA',
        ]);

        $manualPayload = $this->payload();
        $manualPayload['weighings'][0]['weight_source'] = 'MANUAL';
        $manualPayload['weighings'][0]['scale_reading'] = $payload['weighings'][0]['scale_reading'];
        $manualTicketId = $this->postJson('/api/v1/despacho-minorista/tickets', $manualPayload)
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseCount('lecturas_balanza', 1);
        $this->assertDatabaseHas('pesadas', [
            'ticket_id' => $manualTicketId,
            'lectura_balanza_id' => null,
            'origen_peso' => 'MANUAL',
        ]);
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

    public function test_registered_client_dispatch_with_trays_requires_delivery(): void
    {
        $payload = $this->payload();
        unset($payload['delivery']);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'delivery',
                'delivery.vehicle_id',
                'delivery.driver_id',
            ]);

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_retail_dispatch_rejects_inactive_or_non_company_fleet(): void
    {
        $otherUser = User::factory()->create();
        $otherVehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $otherUser->empresa_id,
            'placa' => 'OTR-001',
            'es_propio' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inactiveDriverId = DB::table('conductores')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'nombre_completo' => 'CHOFER INACTIVO',
            'estado' => 'INACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = $this->payload();
        $payload['delivery'] = [
            'vehicle_id' => $otherVehicleId,
            'driver_id' => $inactiveDriverId,
        ];

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'delivery.vehicle_id',
                'delivery.driver_id',
            ]);

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_retail_dispatch_accepts_a_company_vehicle_assigned_to_a_provider(): void
    {
        $providerId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'NIT',
            'numero_documento' => '900999888',
            'nombre_razon_social' => 'Proveedor con camion asignado',
            'direccion' => 'Direccion de proveedor',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert([
            'tercero_id' => $providerId,
            'rol' => 'PROVEEDOR',
            'created_at' => now(),
        ]);
        $providerVehicleId = DB::table('vehiculos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'placa' => 'EXT-001',
            'es_propio' => true,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('proveedor_vehiculos')->insert([
            'proveedor_id' => $providerId,
            'vehiculo_id' => $providerVehicleId,
            'vigente_desde' => today(),
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = $this->payload();
        $payload['delivery']['vehicle_id'] = $providerVehicleId;

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.delivery.vehicle.id', $providerVehicleId)
            ->assertJsonPath('data.delivery.vehicle.plate', 'EXT-001');

        $this->assertDatabaseHas('tickets_despacho', [
            'vehiculo_entrega_id' => $providerVehicleId,
        ]);
    }

    public function test_client_price_always_prevails_over_a_submitted_list_override(): void
    {
        $payload = $this->payload();
        $payload['price_overrides'] = [TipoPollo::CHICKEN_DRESSED => 10.25];

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.prices.POLLO_PELADO.price_kg', 8.5)
            ->assertJsonPath('data.prices.POLLO_PELADO.source', 'CLIENTE')
            ->assertJsonPath('data.prices.POLLO_PELADO.history_id', $this->priceHistoryId)
            ->assertJsonPath('data.weighings.0.price_origin', 'CLIENTE')
            ->assertJsonPath('data.weighings.0.tare_weight_kg', 5)
            ->assertJsonPath('data.weighings.0.net_weight_kg', 7)
            ->assertJsonPath('data.totals.amount', 59.5);

        $this->assertDatabaseHas('ticket_precios', [
            'tipo_pollo_id' => $this->typeId,
            'precio_historial_id' => $this->priceHistoryId,
            'precio_kg' => 8.5,
            'origen_precio' => 'CLIENTE',
        ]);
    }

    public function test_retail_prices_are_rounded_to_two_decimals_in_catalog_and_ticket(): void
    {
        DB::table('precios_historial')
            ->where('id', $this->priceHistoryId)
            ->update(['precio_kg' => 8.5678]);

        $this->getJson('/api/v1/despacho-minorista/catalogo')
            ->assertOk()
            ->assertJsonPath('data.clients.0.prices.POLLO_PELADO.price_kg', 8.57);

        $response = $this->postJson('/api/v1/despacho-minorista/tickets', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.prices.POLLO_PELADO.price_kg', 8.57)
            ->assertJsonPath('data.weighings.0.price_kg', 8.57)
            ->assertJsonPath('data.weighings.0.amount', 59.99)
            ->assertJsonPath('data.totals.amount', 59.99);

        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $response->json('data.id'),
            'precio_kg' => 8.57,
        ]);
    }

    public function test_retail_dispatch_rejects_manual_prices_with_more_than_two_decimals(): void
    {
        $payload = $this->payload();
        $payload['price_overrides'] = [TipoPollo::CHICKEN_DRESSED => 10.251];

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price_overrides.'.TipoPollo::CHICKEN_DRESSED);

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_lists_without_client_keep_their_own_manual_prices(): void
    {
        $generalHistoryId = $this->createGeneralPrice(7.25);
        $first = $this->payload();
        $first['client_id'] = null;
        unset($first['delivery']);
        $first['price_overrides'] = [TipoPollo::CHICKEN_DRESSED => '10.25'];
        $first['payments'] = [$this->paymentPayload('71.75')];
        $second = $this->payload();
        $second['client_id'] = null;
        unset($second['delivery']);
        $second['price_overrides'] = [TipoPollo::CHICKEN_DRESSED => 11.75];
        $second['payments'] = [$this->paymentPayload(82.25)];

        $firstResponse = $this->postJson('/api/v1/despacho-minorista/tickets', $first)
            ->assertCreated()
            ->assertJsonPath('data.client', null)
            ->assertJsonPath('data.prices.POLLO_PELADO.price_kg', 10.25)
            ->assertJsonPath('data.prices.POLLO_PELADO.source', 'MANUAL')
            ->assertJsonPath('data.prices.POLLO_PELADO.history_id', $generalHistoryId);
        $secondResponse = $this->postJson('/api/v1/despacho-minorista/tickets', $second)
            ->assertCreated()
            ->assertJsonPath('data.client', null)
            ->assertJsonPath('data.prices.POLLO_PELADO.price_kg', 11.75)
            ->assertJsonPath('data.prices.POLLO_PELADO.source', 'MANUAL');

        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $firstResponse->json('data.id'),
            'precio_kg' => 10.25,
            'origen_precio' => 'MANUAL',
        ]);
        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $secondResponse->json('data.id'),
            'precio_kg' => 11.75,
            'origen_precio' => 'MANUAL',
        ]);
        $this->assertDatabaseCount('movimientos_javas', 0);
    }

    public function test_list_without_client_uses_the_current_general_price_when_it_has_no_override(): void
    {
        $generalHistoryId = $this->createGeneralPrice(7.25);
        $payload = $this->payload();
        $payload['client_id'] = null;
        unset($payload['delivery']);
        $payload['payments'] = [$this->paymentPayload(50.75)];

        $this->getJson('/api/v1/despacho-minorista/catalogo')
            ->assertOk()
            ->assertJsonPath('data.general_prices.POLLO_PELADO.price_kg', 7.25)
            ->assertJsonPath('data.general_prices.POLLO_PELADO.source', 'GENERAL')
            ->assertJsonPath('data.general_prices.POLLO_PELADO.history_id', $generalHistoryId);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.client', null)
            ->assertJsonPath('data.prices.POLLO_PELADO.price_kg', 7.25)
            ->assertJsonPath('data.prices.POLLO_PELADO.source', 'GENERAL')
            ->assertJsonPath('data.weighings.0.price_kg', 7.25)
            ->assertJsonPath('data.totals.amount', 50.75);

        $this->assertDatabaseHas('pagos', [
            'tipo' => 'COBRO_MINORISTA',
            'cliente_id' => null,
            'cuenta_destino_id' => $this->cashAccountId,
            'importe' => 50.75,
            'estado' => 'REGISTRADO',
        ]);
    }

    public function test_anonymous_retail_sale_requires_full_payment(): void
    {
        $this->createGeneralPrice(7.25);
        $payload = $this->payload();
        $payload['client_id'] = null;
        unset($payload['delivery']);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments');

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_retail_dispatch_rejects_payment_amounts_with_more_than_two_decimals(): void
    {
        $this->createGeneralPrice(7.25);
        $payload = $this->payload();
        $payload['client_id'] = null;
        unset($payload['delivery']);
        $payload['payments'] = [$this->paymentPayload(50.755)];

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments.0.importe');

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_retail_total_sums_each_weighing_after_rounding_it_to_cents(): void
    {
        $this->createGeneralPrice(1.23);
        $payload = $this->payload();
        $payload['client_id'] = null;
        unset($payload['delivery']);
        $payload['weighings'][0]['tray_count'] = 0;
        $payload['weighings'][0]['read_weight_kg'] = 1.004;
        $payload['weighings'][] = [
            ...$payload['weighings'][0],
            'local_id' => 2,
        ];
        $payload['payments'] = [$this->paymentPayload(2.46)];

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.weighings.0.amount', 1.23)
            ->assertJsonPath('data.weighings.1.amount', 1.23)
            ->assertJsonPath('data.totals.amount', 2.46);

        $this->assertDatabaseHas('pagos', [
            'importe' => 2.46,
        ]);
    }

    public function test_return_amounts_are_serialized_with_negative_sign(): void
    {
        $payload = $this->payload();
        $payload['operation_type'] = TicketDespacho::OPERATION_RETURN;
        unset($payload['delivery']);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.operation_type', TicketDespacho::OPERATION_RETURN)
            ->assertJsonPath('data.weighings.0.amount', -59.5)
            ->assertJsonPath('data.totals.amount', -59.5);

        $this->assertDatabaseHas('tickets_despacho', [
            'canal' => TicketDespacho::CHANNEL_RETAIL,
            'tipo_operacion' => TicketDespacho::OPERATION_RETURN,
            'vehiculo_entrega_id' => null,
            'conductor_entrega_id' => null,
        ]);
        $this->assertDatabaseCount('movimientos_javas', 0);
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

    public function test_retail_dispatch_allows_weighing_without_trays(): void
    {
        $this->getJson('/api/v1/despacho-minorista/catalogo')->assertOk();
        DB::table('ajustes_peso_minorista')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('estacion', 1)
            ->where('codigo', AjustePesoMinorista::MALE_CLOSED)
            ->update(['gramos_adicionales' => 250]);
        $payload = $this->payload();
        $payload['weighings'][0]['tray_count'] = 0;
        unset($payload['delivery']);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.totals.trays', 0)
            ->assertJsonPath('data.totals.birds', 0)
            ->assertJsonPath('data.totals.gross_weight_kg', 12)
            ->assertJsonPath('data.totals.net_weight_kg', 12)
            ->assertJsonPath('data.weighings.0.tray_count', 0)
            ->assertJsonPath('data.weighings.0.birds', 0)
            ->assertJsonPath('data.weighings.0.tare_weight_kg', 0)
            ->assertJsonPath('data.weighings.0.gross_weight_kg', 12)
            ->assertJsonPath('data.weighings.0.net_weight_kg', 12);

        $this->assertDatabaseHas('pesadas', [
            'cantidad_bandejas' => 0,
            'cantidad_aves' => 0,
            'peso_leido_kg' => 12,
            'ajuste_peso_gramos' => 250,
            'peso_bruto_kg' => 12,
            'tara_total_kg' => 0,
            'peso_neto_kg' => 12,
        ]);
        $this->assertDatabaseHas('tickets_despacho', [
            'cliente_destino_id' => $this->clientId,
            'vehiculo_entrega_id' => null,
            'conductor_entrega_id' => null,
        ]);
        $this->assertDatabaseCount('movimientos_javas', 0);
    }

    public function test_retail_dispatch_rejects_a_non_retail_weight_source(): void
    {
        $payload = $this->payload();
        $payload['weighings'][0]['weight_source'] = 'BALANZA_1';

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.weight_source');

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_each_retail_endpoint_rejects_the_other_stations_scale_source(): void
    {
        $stationOnePayload = $this->payload();
        $stationOnePayload['weighings'][0]['weight_source'] = 'BALANZA_MINORISTA_2';
        $this->postJson('/api/v1/despacho-minorista/tickets', $stationOnePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.weight_source');

        $stationTwoPayload = $this->payload();
        $stationTwoPayload['weighings'][0]['weight_source'] = 'BALANZA_MINORISTA';
        $this->postJson('/api/v1/despacho-minorista-2/tickets', $stationTwoPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.weight_source');

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('lecturas_balanza', 0);
    }

    public function test_retail_dispatch_rejects_live_chicken(): void
    {
        $payload = $this->payload();
        $payload['weighings'][0]['chicken_type_code'] = TipoPollo::CHICKEN_LIVE;

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.chicken_type_code');

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_retail_dispatch_rejects_more_than_ten_birds_per_tray(): void
    {
        $payload = $this->payload();
        $payload['weighings'][0]['birds_per_tray'] = 11;

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weighings.0.birds_per_tray');

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
        $this->assertDatabaseCount('lecturas_balanza', 1);
    }

    public function test_retail_idempotency_rejects_a_uuid_from_another_branch(): void
    {
        $payload = $this->payload();

        $ticketId = $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->json('data.id');
        $otherBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'MINORISTA-SECUNDARIA',
            'nombre' => 'Sucursal minorista secundaria',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journeyId = DB::table('tickets_despacho')->where('id', $ticketId)->value('jornada_id');
        DB::table('jornadas_operativas')
            ->where('id', $journeyId)
            ->update(['sucursal_id' => $otherBranchId]);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft_id');

        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseCount('pesadas', 1);
        $this->assertDatabaseCount('lecturas_balanza', 1);
    }

    public function test_retail_idempotency_rejects_a_draft_registered_by_the_other_station(): void
    {
        $stationOnePayload = $this->payload();
        $this->postJson('/api/v1/despacho-minorista/tickets', $stationOnePayload)
            ->assertCreated();

        $stationTwoPayload = $stationOnePayload;
        $stationTwoPayload['weighings'][0]['weight_source'] = 'BALANZA_MINORISTA_2';
        $this->postJson('/api/v1/despacho-minorista-2/tickets', $stationTwoPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft_id');

        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseCount('pesadas', 1);
        $this->assertDatabaseCount('lecturas_balanza', 1);
        $this->assertDatabaseMissing('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => 'BALANZA_MINORISTA_2',
        ]);
    }

    public function test_manual_retail_idempotency_is_also_scoped_to_its_station(): void
    {
        $payload = $this->payload();
        $payload['weighings'][0]['weight_source'] = 'MANUAL';

        $this->postJson('/api/v1/despacho-minorista-2/tickets', $payload)
            ->assertCreated();
        $this->postJson('/api/v1/despacho-minorista-2/tickets', $payload)
            ->assertOk()
            ->assertJsonPath('already_registered', true);
        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft_id');

        $this->assertDatabaseCount('tickets_despacho', 1);
        $this->assertDatabaseCount('pesadas', 1);
        $this->assertDatabaseCount('lecturas_balanza', 0);
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
            'delivery' => [
                'vehicle_id' => $this->deliveryVehicleId,
                'driver_id' => $this->deliveryDriverId,
            ],
            'weighings' => [[
                'local_id' => 1,
                'chicken_type_code' => TipoPollo::CHICKEN_DRESSED,
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

    private function createGeneralPrice(float $price): int
    {
        $listId = DB::table('listas_precios')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => null,
            'codigo' => 'GENERAL-MINORISTA',
            'nombre' => 'Precios generales minoristas',
            'operacion' => 'VENTA',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $listId,
            'tipo_pollo_id' => $this->typeId,
            'precio_kg' => $price,
            'vigente_desde' => now()->subMinute(),
            'vigente_hasta' => null,
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function paymentPayload(float|string $amount): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'metodo_pago_id' => $this->cashMethodId,
            'cuenta_destino_id' => $this->cashAccountId,
            'moneda' => 'PEN',
            'importe' => $amount,
        ];
    }
}
