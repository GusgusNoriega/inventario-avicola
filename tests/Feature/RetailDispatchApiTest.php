<?php

namespace Tests\Feature;

use App\Models\AjustePesoMinorista;
use App\Models\Balanza;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tercero;
use App\Models\TicketDespacho;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\ClientJourneyPriceService;
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
            ->assertJsonPath('data.financial.default_method_id', $this->cashMethodId)
            ->assertJsonPath('data.financial.default_account_id', $this->cashAccountId)
            ->assertJsonMissingPath('data.cage_types');

        $this->assertDatabaseHas('balanzas', [
            'sucursal_id' => $this->branchId,
            'codigo' => 'BALANZA_MINORISTA',
        ]);
        $this->assertDatabaseHas('configuraciones_despacho_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'estacion' => 1,
            'metodo_pago_id' => $this->cashMethodId,
            'cuenta_destino_id' => $this->cashAccountId,
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

    public function test_retail_payment_defaults_are_persisted_independently_for_both_stations(): void
    {
        $entityId = (int) DB::table('cuentas_financieras')
            ->where('id', $this->cashAccountId)
            ->value('entidad_financiera_id');
        $bankAccountId = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId,
            'tipo' => 'BANCO',
            'alias' => 'Cuenta bancaria puesto 2',
            'banco' => 'Banco de prueba',
            'numero_cuenta' => '001122334455',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transferMethodId = (int) DB::table('metodos_pago')
            ->where('codigo', 'TRANSFERENCIA')
            ->value('id');

        $this->getJson('/api/v1/despacho-minorista/catalogo')->assertOk();
        $this->getJson('/api/v1/despacho-minorista-2/catalogo')->assertOk();

        $this->putJson(
            '/api/v1/despacho-minorista/configuracion',
            $this->configurationPayload([
                'method_id' => $this->cashMethodId,
                'account_id' => $this->cashAccountId,
            ])
        )->assertOk()
            ->assertJsonPath('data.payment_defaults.method_id', $this->cashMethodId)
            ->assertJsonPath('data.payment_defaults.account_id', $this->cashAccountId);

        $this->putJson(
            '/api/v1/despacho-minorista-2/configuracion',
            $this->configurationPayload([
                'method_id' => $transferMethodId,
                'account_id' => $bankAccountId,
            ])
        )->assertOk()
            ->assertJsonPath('data.payment_defaults.method_id', $transferMethodId)
            ->assertJsonPath('data.payment_defaults.account_id', $bankAccountId);

        $this->getJson('/api/v1/despacho-minorista/catalogo')
            ->assertOk()
            ->assertJsonPath('data.financial.default_method_id', $this->cashMethodId)
            ->assertJsonPath('data.financial.default_account_id', $this->cashAccountId);
        $this->getJson('/api/v1/despacho-minorista-2/catalogo')
            ->assertOk()
            ->assertJsonPath('data.financial.default_method_id', $transferMethodId)
            ->assertJsonPath('data.financial.default_account_id', $bankAccountId);

        $this->assertDatabaseHas('configuraciones_despacho_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'estacion' => 1,
            'metodo_pago_id' => $this->cashMethodId,
            'cuenta_destino_id' => $this->cashAccountId,
        ]);
        $this->assertDatabaseHas('configuraciones_despacho_minorista', [
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'estacion' => 2,
            'metodo_pago_id' => $transferMethodId,
            'cuenta_destino_id' => $bankAccountId,
        ]);
    }

    public function test_retail_payment_defaults_reject_invalid_method_or_account(): void
    {
        $inactiveMethodId = (int) DB::table('metodos_pago')
            ->where('codigo', 'YAPE')
            ->value('id');
        DB::table('metodos_pago')
            ->where('id', $inactiveMethodId)
            ->update(['estado' => 'INACTIVO']);

        $this->putJson(
            '/api/v1/despacho-minorista/configuracion',
            $this->configurationPayload([
                'method_id' => $inactiveMethodId,
                'account_id' => $this->cashAccountId,
            ])
        )->assertUnprocessable()
            ->assertJsonValidationErrors('payment_defaults.method_id');

        $otherUser = User::factory()->create();
        $foreignEntityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $otherUser->empresa_id,
            'tipo' => 'PROPIA',
            'razon_social' => 'Caja de otra empresa',
            'estado' => 'ACTIVO',
            'created_by' => $otherUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignAccountId = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $foreignEntityId,
            'tipo' => 'CAJA',
            'alias' => 'Caja ajena',
            'moneda' => 'PEN',
            'estado' => 'ACTIVO',
            'created_by' => $otherUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson(
            '/api/v1/despacho-minorista/configuracion',
            $this->configurationPayload([
                'method_id' => $this->cashMethodId,
                'account_id' => $foreignAccountId,
            ])
        )->assertUnprocessable()
            ->assertJsonValidationErrors('payment_defaults.account_id');

        $ownEntityId = (int) DB::table('cuentas_financieras')
            ->where('id', $this->cashAccountId)
            ->value('entidad_financiera_id');
        $usdAccountId = DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $ownEntityId,
            'tipo' => 'BANCO',
            'alias' => 'Cuenta USD no compatible',
            'moneda' => 'USD',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson(
            '/api/v1/despacho-minorista/configuracion',
            $this->configurationPayload([
                'method_id' => $this->cashMethodId,
                'account_id' => $usdAccountId,
            ])
        )->assertUnprocessable()
            ->assertJsonValidationErrors('payment_defaults.account_id');

        $this->putJson(
            '/api/v1/despacho-minorista/configuracion',
            $this->configurationPayload([
                'method_id' => $this->cashMethodId,
                'account_id' => null,
            ])
        )->assertUnprocessable()
            ->assertJsonValidationErrors('payment_defaults.account_id');
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
            ->assertJsonPath('data.delivery.mode', TicketDespacho::DELIVERY_MODE_COMPANY_TRUCK)
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

    public function test_both_retail_stations_register_repeated_equal_scale_readings_as_separate_weighings(): void
    {
        foreach ([
            1 => [
                'base_url' => '/api/v1/despacho-minorista',
                'weight_source' => 'BALANZA_MINORISTA',
            ],
            2 => [
                'base_url' => '/api/v1/despacho-minorista-2',
                'weight_source' => 'BALANZA_MINORISTA_2',
            ],
        ] as $station => $stationData) {
            $capturedAt = now('America/Lima')->subMinute()->startOfSecond()->toIso8601String();
            $payload = $this->payload();
            $payload['weighings'][0]['weight_source'] = $stationData['weight_source'];
            $payload['weighings'][0]['read_weight_kg'] = 0.950;
            $payload['weighings'][0]['tray_count'] = 0;
            $payload['weighings'][0]['weighed_at'] = $capturedAt;
            $payload['weighings'][0]['scale_reading'] = [
                'raw_frame' => 'ST,NET 0.950 kg',
                'connection_mode' => 'SERIAL',
                'device_name' => "Balanza minorista {$station}",
                'captured_at' => $capturedAt,
            ];
            $secondWeighing = $payload['weighings'][0];
            $secondWeighing['local_id'] = 2;
            $payload['weighings'][] = $secondWeighing;

            $response = $this->postJson("{$stationData['base_url']}/tickets", $payload)
                ->assertCreated()
                ->assertJsonCount(2, 'data.weighings')
                ->assertJsonPath('data.weighings.0.read_weight_kg', 0.95)
                ->assertJsonPath('data.weighings.1.read_weight_kg', 0.95);

            $ticketId = (int) $response->json('data.id');
            $this->assertSame(
                2,
                DB::table('pesadas')->where('ticket_id', $ticketId)->count()
            );
            $this->assertSame(
                2,
                DB::table('pesadas')
                    ->where('ticket_id', $ticketId)
                    ->where('origen_peso', $stationData['weight_source'])
                    ->where('peso_leido_kg', 0.950)
                    ->distinct()
                    ->count('lectura_balanza_id')
            );
        }
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

    public function test_registered_client_dispatch_with_trays_requires_delivery_mode(): void
    {
        $payload = $this->payload();
        unset($payload['delivery']);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'delivery',
                'delivery.mode',
            ]);

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_customer_pickup_keeps_trays_on_the_client_without_fleet_at_both_retail_stations(): void
    {
        DB::table('vehiculos')->update(['estado' => 'INACTIVO']);
        DB::table('conductores')->update(['estado' => 'INACTIVO']);

        foreach ([
            [
                'endpoint' => '/api/v1/despacho-minorista/tickets',
                'weight_source' => 'BALANZA_MINORISTA',
            ],
            [
                'endpoint' => '/api/v1/despacho-minorista-2/tickets',
                'weight_source' => 'BALANZA_MINORISTA_2',
            ],
        ] as $station) {
            $payload = $this->payload();
            $payload['delivery'] = [
                'mode' => TicketDespacho::DELIVERY_MODE_CUSTOMER_PICKUP,
            ];
            $payload['weighings'][0]['weight_source'] = $station['weight_source'];

            $response = $this->postJson($station['endpoint'], $payload)
                ->assertCreated()
                ->assertJsonPath('data.delivery.mode', TicketDespacho::DELIVERY_MODE_CUSTOMER_PICKUP)
                ->assertJsonPath('data.delivery.vehicle', null)
                ->assertJsonPath('data.delivery.driver', null)
                ->assertJsonPath('data.totals.trays', 2);
            $ticketId = (int) $response->json('data.id');

            $this->assertDatabaseHas('tickets_despacho', [
                'id' => $ticketId,
                'cliente_destino_id' => $this->clientId,
                'vehiculo_entrega_id' => null,
                'conductor_entrega_id' => null,
            ]);
            $this->assertDatabaseHas('pesadas', [
                'ticket_id' => $ticketId,
                'cantidad_bandejas' => 2,
                'cantidad_aves' => 10,
            ]);
            $this->assertDatabaseHas('movimientos_javas', [
                'ticket_despacho_id' => $ticketId,
                'cliente_id' => $this->clientId,
                'tipo' => 'DESPACHO',
                'cantidad_bandejas' => 2,
                'vehiculo_id' => null,
                'conductor_id' => null,
            ]);

            $this->postJson($station['endpoint'], $payload)
                ->assertOk()
                ->assertJsonPath('already_registered', true)
                ->assertJsonPath('data.delivery.mode', TicketDespacho::DELIVERY_MODE_CUSTOMER_PICKUP);
            $this->assertSame(
                1,
                DB::table('movimientos_javas')
                    ->where('ticket_despacho_id', $ticketId)
                    ->count()
            );
        }

        $this->getJson('/api/v1/control-javas')
            ->assertOk()
            ->assertJsonPath('data.summary.tray_total_pending', 4)
            ->assertJsonPath('data.clients.0.id', $this->clientId)
            ->assertJsonPath('data.clients.0.tray_balance', 4);
    }

    public function test_company_truck_mode_requires_vehicle_and_driver(): void
    {
        $payload = $this->payload();
        $payload['delivery'] = [
            'mode' => TicketDespacho::DELIVERY_MODE_COMPANY_TRUCK,
        ];

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'delivery.vehicle_id',
                'delivery.driver_id',
            ]);

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_customer_pickup_rejects_residual_vehicle_and_driver(): void
    {
        $payload = $this->payload();
        $payload['delivery']['mode'] = TicketDespacho::DELIVERY_MODE_CUSTOMER_PICKUP;

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'delivery.vehicle_id',
                'delivery.driver_id',
            ]);

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_retail_dispatch_rejects_an_unknown_delivery_mode(): void
    {
        $payload = $this->payload();
        $payload['delivery'] = ['mode' => 'MODO_DESCONOCIDO'];

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delivery.mode');

        $this->assertDatabaseCount('tickets_despacho', 0);
    }

    public function test_legacy_company_truck_payload_without_mode_remains_compatible(): void
    {
        $payload = $this->payload();
        unset($payload['delivery']['mode']);

        $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.delivery.mode', TicketDespacho::DELIVERY_MODE_COMPANY_TRUCK)
            ->assertJsonPath('data.delivery.vehicle.id', $this->deliveryVehicleId)
            ->assertJsonPath('data.delivery.driver.id', $this->deliveryDriverId);
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

    public function test_manual_ticket_price_prevails_for_registered_clients_at_both_retail_stations_without_changing_client_price(): void
    {
        $priceListCount = DB::table('listas_precios')->count();
        $priceHistoryCount = DB::table('precios_historial')->count();
        $stations = [
            [
                'endpoint' => '/api/v1/despacho-minorista/tickets',
                'catalog' => '/api/v1/despacho-minorista/catalogo',
                'scale_code' => Balanza::CODE_RETAIL_1,
            ],
            [
                'endpoint' => '/api/v1/despacho-minorista-2/tickets',
                'catalog' => '/api/v1/despacho-minorista-2/catalogo',
                'scale_code' => Balanza::CODE_RETAIL_2,
            ],
        ];

        foreach ($stations as $station) {
            $manualPayload = $this->payload();
            $manualPayload['weighings'][0]['weight_source'] = $station['scale_code'];
            $manualPayload['price_overrides'] = [TipoPollo::CHICKEN_DRESSED => 10.25];
            $manualPayload['payments'] = [$this->paymentPayload(71.75)];

            $manualResponse = $this->postJson($station['endpoint'], $manualPayload)
                ->assertCreated()
                ->assertJsonPath('data.prices.POLLO_PELADO.price_kg', 10.25)
                ->assertJsonPath('data.prices.POLLO_PELADO.source', 'MANUAL')
                ->assertJsonPath('data.prices.POLLO_PELADO.history_id', $this->priceHistoryId)
                ->assertJsonPath('data.weighings.0.price_kg', 10.25)
                ->assertJsonPath('data.weighings.0.price_origin', 'MANUAL')
                ->assertJsonPath('data.weighings.0.tare_weight_kg', 5)
                ->assertJsonPath('data.weighings.0.net_weight_kg', 7)
                ->assertJsonPath('data.totals.amount', 71.75);

            $this->assertDatabaseHas('ticket_precios', [
                'ticket_id' => $manualResponse->json('data.id'),
                'tipo_pollo_id' => $this->typeId,
                'precio_historial_id' => $this->priceHistoryId,
                'precio_kg' => 10.25,
                'origen_precio' => 'MANUAL',
            ]);

            $configuredPayload = $this->payload();
            $configuredPayload['weighings'][0]['weight_source'] = $station['scale_code'];

            $configuredResponse = $this->postJson($station['endpoint'], $configuredPayload)
                ->assertCreated()
                ->assertJsonPath('data.prices.POLLO_PELADO.price_kg', 8.5)
                ->assertJsonPath('data.prices.POLLO_PELADO.source', 'CLIENTE')
                ->assertJsonPath('data.prices.POLLO_PELADO.history_id', $this->priceHistoryId)
                ->assertJsonPath('data.weighings.0.price_origin', 'CLIENTE')
                ->assertJsonPath('data.totals.amount', 59.5);

            $this->assertDatabaseHas('ticket_precios', [
                'ticket_id' => $configuredResponse->json('data.id'),
                'tipo_pollo_id' => $this->typeId,
                'precio_historial_id' => $this->priceHistoryId,
                'precio_kg' => 8.5,
                'origen_precio' => 'CLIENTE',
            ]);

            $this->getJson($station['catalog'])
                ->assertOk()
                ->assertJsonPath('data.clients.0.prices.POLLO_PELADO.price_kg', 8.5)
                ->assertJsonPath('data.clients.0.prices.POLLO_PELADO.source', 'CLIENTE');
        }

        $this->assertSame($priceListCount, DB::table('listas_precios')->count());
        $this->assertSame($priceHistoryCount, DB::table('precios_historial')->count());
        $this->assertEquals(
            8.5,
            (float) DB::table('precios_historial')
                ->where('id', $this->priceHistoryId)
                ->value('precio_kg')
        );
    }

    public function test_manual_ticket_price_is_not_revalued_when_the_client_price_changes(): void
    {
        $manualPayload = $this->payload();
        $manualPayload['price_overrides'] = [TipoPollo::CHICKEN_DRESSED => 10.25];
        $manualTicketId = $this->postJson('/api/v1/despacho-minorista/tickets', $manualPayload)
            ->assertCreated()
            ->json('data.id');
        $configuredTicketId = $this->postJson(
            '/api/v1/despacho-minorista/tickets',
            $this->payload()
        )->assertCreated()->json('data.id');
        $priceListId = (int) DB::table('precios_historial')
            ->where('id', $this->priceHistoryId)
            ->value('lista_precio_id');
        $effectiveAt = now()->addSecond();

        DB::table('precios_historial')
            ->where('id', $this->priceHistoryId)
            ->update(['vigente_hasta' => $effectiveAt]);
        $newHistoryId = DB::table('precios_historial')->insertGetId([
            'lista_precio_id' => $priceListId,
            'tipo_pollo_id' => $this->typeId,
            'precio_kg' => 9.25,
            'vigente_desde' => $effectiveAt,
            'vigente_hasta' => null,
            'motivo_cambio' => 'Cambio de tarifa durante la jornada',
            'reemplaza_precio_id' => $this->priceHistoryId,
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);

        $updated = app(ClientJourneyPriceService::class)->refresh(
            Tercero::query()->findOrFail($this->clientId),
            $this->user->id,
            [TipoPollo::CHICKEN_DRESSED]
        );

        $this->assertSame(1, $updated);
        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $manualTicketId,
            'tipo_pollo_id' => $this->typeId,
            'precio_historial_id' => $this->priceHistoryId,
            'precio_kg' => 10.25,
            'origen_precio' => 'MANUAL',
        ]);
        $this->assertDatabaseHas('ticket_precios', [
            'ticket_id' => $configuredTicketId,
            'tipo_pollo_id' => $this->typeId,
            'precio_historial_id' => $newHistoryId,
            'precio_kg' => 9.25,
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

    public function test_both_retail_stations_accept_transfer_payments_without_a_reference(): void
    {
        $this->createGeneralPrice(7.25);
        $transferMethodId = (int) DB::table('metodos_pago')
            ->where('codigo', 'TRANSFERENCIA')
            ->value('id');

        foreach ([
            '/api/v1/despacho-minorista/tickets',
            '/api/v1/despacho-minorista-2/tickets',
        ] as $endpoint) {
            $payload = $this->payload();
            $payload['client_id'] = null;
            unset($payload['delivery']);
            $payload['weighings'][0]['weight_source'] = 'MANUAL';
            $payload['payments'] = [[
                ...$this->paymentPayload(50.75),
                'metodo_pago_id' => $transferMethodId,
                'referencia' => null,
            ]];

            $this->postJson($endpoint, $payload)
                ->assertCreated();
        }

        $this->assertDatabaseCount('pagos', 2);
        $this->assertDatabaseHas('pagos', [
            'tipo' => 'COBRO_MINORISTA',
            'metodo_pago_id' => $transferMethodId,
            'referencia' => null,
        ]);
    }

    public function test_both_retail_stations_allow_customer_credit_and_create_a_receivable(): void
    {
        foreach ([
            '/api/v1/despacho-minorista/tickets',
            '/api/v1/despacho-minorista-2/tickets',
        ] as $endpoint) {
            $payload = $this->payload();
            $payload['weighings'][0]['weight_source'] = 'MANUAL';
            $payload['payments'] = [];

            $ticketId = $this->postJson($endpoint, $payload)
                ->assertCreated()
                ->assertJsonPath('data.client.id', $this->clientId)
                ->assertJsonPath('data.totals.amount', 59.5)
                ->json('data.id');
            $document = DB::table('comprobantes')
                ->where('origen_clave', "VENTA:TICKET:{$ticketId}")
                ->first();

            $this->assertNotNull($document);
            $this->assertDatabaseHas('comprobantes', [
                'id' => $document->id,
                'tercero_id' => $this->clientId,
                'operacion' => 'VENTA',
                'naturaleza' => 'CARGO',
                'total' => 59.5,
                'saldo_pendiente' => 59.5,
                'estado' => 'PENDIENTE',
            ]);
            $this->assertDatabaseHas('comprobante_tickets', [
                'comprobante_id' => $document->id,
                'ticket_id' => $ticketId,
                'importe_aplicado' => 59.5,
            ]);
        }

        $this->assertDatabaseCount('tickets_despacho', 2);
        $this->assertDatabaseCount('comprobantes', 2);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('pago_aplicaciones', 0);
    }

    public function test_both_retail_stations_allow_a_customer_to_pay_when_the_sale_is_registered(): void
    {
        foreach ([
            '/api/v1/despacho-minorista/tickets',
            '/api/v1/despacho-minorista-2/tickets',
        ] as $endpoint) {
            $payload = $this->payload();
            $payload['weighings'][0]['weight_source'] = 'MANUAL';
            $payment = $this->paymentPayload(59.5);
            $payload['payments'] = [$payment];

            $ticketId = $this->postJson($endpoint, $payload)
                ->assertCreated()
                ->assertJsonPath('data.client.id', $this->clientId)
                ->assertJsonPath('data.totals.amount', 59.5)
                ->json('data.id');
            $document = DB::table('comprobantes')
                ->where('origen_clave', "VENTA:TICKET:{$ticketId}")
                ->first();
            $paymentId = DB::table('pagos')
                ->where('idempotency_key', $payment['idempotency_key'])
                ->value('id');

            $this->assertNotNull($document);
            $this->assertNotNull($paymentId);
            $this->assertDatabaseHas('comprobantes', [
                'id' => $document->id,
                'tercero_id' => $this->clientId,
                'total' => 59.5,
                'saldo_pendiente' => 0,
                'estado' => 'PAGADO',
            ]);
            $this->assertDatabaseHas('pagos', [
                'id' => $paymentId,
                'tipo' => 'COBRO_MINORISTA',
                'cliente_id' => $this->clientId,
                'cuenta_destino_id' => $this->cashAccountId,
                'importe' => 59.5,
                'estado' => 'REGISTRADO',
            ]);
            $this->assertDatabaseHas('pago_aplicaciones', [
                'pago_id' => $paymentId,
                'lado' => 'CXC',
                'comprobante_id' => $document->id,
                'importe_aplicado' => 59.5,
            ]);
        }

        $this->assertDatabaseCount('tickets_despacho', 2);
        $this->assertDatabaseCount('comprobantes', 2);
        $this->assertDatabaseCount('pagos', 2);
        $this->assertDatabaseCount('pago_aplicaciones', 2);
    }

    public function test_customer_can_make_a_partial_payment_and_keep_the_remaining_receivable(): void
    {
        $payload = $this->payload();
        $payment = $this->paymentPayload(20);
        $payload['payments'] = [$payment];

        $ticketId = $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.client.id', $this->clientId)
            ->assertJsonPath('data.totals.amount', 59.5)
            ->json('data.id');
        $document = DB::table('comprobantes')
            ->where('origen_clave', "VENTA:TICKET:{$ticketId}")
            ->first();
        $paymentId = DB::table('pagos')
            ->where('idempotency_key', $payment['idempotency_key'])
            ->value('id');

        $this->assertNotNull($document);
        $this->assertNotNull($paymentId);
        $this->assertDatabaseHas('comprobantes', [
            'id' => $document->id,
            'tercero_id' => $this->clientId,
            'total' => 59.5,
            'saldo_pendiente' => 39.5,
            'estado' => 'PARCIAL',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $paymentId,
            'tipo' => 'COBRO_MINORISTA',
            'cliente_id' => $this->clientId,
            'importe' => 20,
            'estado' => 'REGISTRADO',
        ]);
        $this->assertDatabaseHas('pago_aplicaciones', [
            'pago_id' => $paymentId,
            'lado' => 'CXC',
            'comprobante_id' => $document->id,
            'importe_aplicado' => 20,
        ]);
    }

    public function test_both_retail_stations_reject_credit_without_a_customer(): void
    {
        $this->createGeneralPrice(7.25);

        foreach ([
            '/api/v1/despacho-minorista/tickets',
            '/api/v1/despacho-minorista-2/tickets',
        ] as $endpoint) {
            foreach ([
                [
                    'payments' => [],
                    'message' => 'Una venta sin cliente debe registrar el pago completo.',
                ],
                [
                    'payments' => [$this->paymentPayload(10)],
                    'message' => 'Una venta sin cliente debe quedar pagada completamente antes de cerrar.',
                ],
            ] as $case) {
                $payload = $this->payload();
                $payload['client_id'] = null;
                unset($payload['delivery']);
                $payload['weighings'][0]['weight_source'] = 'MANUAL';
                $payload['payments'] = $case['payments'];

                $this->postJson($endpoint, $payload)
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('payments')
                    ->assertJsonPath('errors.payments.0', $case['message']);
            }
        }

        $this->assertDatabaseCount('tickets_despacho', 0);
        $this->assertDatabaseCount('comprobantes', 0);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseCount('pago_aplicaciones', 0);
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

    public function test_both_retail_stations_keep_loose_birds_and_apply_adjustment_per_bird_without_trays(): void
    {
        foreach ([
            1 => [
                'base_url' => '/api/v1/despacho-minorista',
                'weight_source' => 'BALANZA_MINORISTA',
            ],
            2 => [
                'base_url' => '/api/v1/despacho-minorista-2',
                'weight_source' => 'BALANZA_MINORISTA_2',
            ],
        ] as $station => $stationData) {
            $this->getJson("{$stationData['base_url']}/catalogo")->assertOk();
            DB::table('ajustes_peso_minorista')
                ->where('empresa_id', $this->user->empresa_id)
                ->where('estacion', $station)
                ->where('codigo', AjustePesoMinorista::MALE_CLOSED)
                ->update(['gramos_adicionales' => 250]);
            $payload = $this->payload();
            $payload['weighings'][0]['weight_source'] = $stationData['weight_source'];
            $payload['weighings'][0]['tray_count'] = 0;
            unset($payload['delivery']);

            $response = $this->postJson("{$stationData['base_url']}/tickets", $payload)
                ->assertCreated()
                ->assertJsonPath('data.totals.trays', 0)
                ->assertJsonPath('data.totals.birds', 5)
                ->assertJsonPath('data.totals.read_weight_kg', 12)
                ->assertJsonPath('data.totals.gross_weight_kg', 13.25)
                ->assertJsonPath('data.totals.tare_weight_kg', 0)
                ->assertJsonPath('data.totals.net_weight_kg', 13.25)
                ->assertJsonPath('data.weighings.0.tray_count', 0)
                ->assertJsonPath('data.weighings.0.birds_per_tray', 5)
                ->assertJsonPath('data.weighings.0.birds', 5)
                ->assertJsonPath('data.weighings.0.adjustment.additional_grams', 250)
                ->assertJsonPath('data.weighings.0.tare_weight_kg', 0)
                ->assertJsonPath('data.weighings.0.gross_weight_kg', 13.25)
                ->assertJsonPath('data.weighings.0.net_weight_kg', 13.25);

            $ticketId = (int) $response->json('data.id');
            $this->assertDatabaseHas('pesadas', [
                'ticket_id' => $ticketId,
                'cantidad_bandejas' => 0,
                'cantidad_aves' => 5,
                'peso_leido_kg' => 12,
                'ajuste_peso_gramos' => 250,
                'peso_bruto_kg' => 13.25,
                'tara_total_kg' => 0,
                'peso_neto_kg' => 13.25,
            ]);
            $this->assertDatabaseHas('tickets_despacho', [
                'id' => $ticketId,
                'cliente_destino_id' => $this->clientId,
                'vehiculo_entrega_id' => null,
                'conductor_entrega_id' => null,
            ]);
            $this->assertDatabaseMissing('movimientos_javas', [
                'ticket_despacho_id' => $ticketId,
            ]);
        }
    }

    public function test_both_retail_stations_apply_zero_adjustment_to_processed_chicken(): void
    {
        $processedTypeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => TipoPollo::CHICKEN_PROCESSED,
            'nombre' => 'Pollo beneficiado',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $clientPriceListId = (int) DB::table('listas_precios')
            ->where('empresa_id', $this->user->empresa_id)
            ->where('tercero_id', $this->clientId)
            ->value('id');
        DB::table('precios_historial')->insert([
            'lista_precio_id' => $clientPriceListId,
            'tipo_pollo_id' => $processedTypeId,
            'precio_kg' => 10,
            'vigente_desde' => now()->subMinute(),
            'vigente_hasta' => null,
            'registrado_por' => $this->user->id,
            'created_at' => now(),
        ]);
        DB::table('tipos_bandeja')
            ->where('codigo', 'BANDEJA_ESTANDAR')
            ->update(['peso_kg' => 0.5]);

        foreach ([
            1 => [
                'base_url' => '/api/v1/despacho-minorista',
                'weight_source' => 'BALANZA_MINORISTA',
            ],
            2 => [
                'base_url' => '/api/v1/despacho-minorista-2',
                'weight_source' => 'BALANZA_MINORISTA_2',
            ],
        ] as $station => $stationData) {
            $this->getJson("{$stationData['base_url']}/catalogo")->assertOk();
            DB::table('ajustes_peso_minorista')
                ->where('empresa_id', $this->user->empresa_id)
                ->where('estacion', $station)
                ->where('codigo', AjustePesoMinorista::MALE_CLOSED)
                ->update(['gramos_adicionales' => 300]);
            $payload = $this->payload();
            $payload['weighings'][0]['chicken_type_code'] = TipoPollo::CHICKEN_PROCESSED;
            $payload['weighings'][0]['weight_source'] = $stationData['weight_source'];

            $response = $this->postJson("{$stationData['base_url']}/tickets", $payload)
                ->assertCreated()
                ->assertJsonPath('data.totals.trays', 2)
                ->assertJsonPath('data.totals.birds', 10)
                ->assertJsonPath('data.totals.read_weight_kg', 12)
                ->assertJsonPath('data.totals.gross_weight_kg', 12)
                ->assertJsonPath('data.totals.tare_weight_kg', 1)
                ->assertJsonPath('data.totals.net_weight_kg', 11)
                ->assertJsonPath('data.totals.amount', 110)
                ->assertJsonPath('data.weighings.0.chicken_type_code', TipoPollo::CHICKEN_PROCESSED)
                ->assertJsonPath('data.weighings.0.birds', 10)
                ->assertJsonPath('data.weighings.0.adjustment.additional_grams', 0)
                ->assertJsonPath('data.weighings.0.gross_weight_kg', 12)
                ->assertJsonPath('data.weighings.0.tare_weight_kg', 1)
                ->assertJsonPath('data.weighings.0.net_weight_kg', 11);

            $this->assertDatabaseHas('pesadas', [
                'ticket_id' => (int) $response->json('data.id'),
                'tipo_pollo_id' => $processedTypeId,
                'cantidad_bandejas' => 2,
                'cantidad_aves' => 10,
                'peso_leido_kg' => 12,
                'ajuste_peso_gramos' => 0,
                'peso_bruto_kg' => 12,
                'tara_total_kg' => 1,
                'peso_neto_kg' => 11,
            ]);
        }
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

    public function test_retail_validation_errors_are_explained_in_spanish(): void
    {
        $payload = $this->payload();
        unset($payload['operation_type'], $payload['weighings'][0]['weighed_at']);
        $payload['weighings'][0]['birds_per_tray'] = 'muchas';

        $response = $this->postJson('/api/v1/despacho-minorista/tickets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'operation_type',
                'weighings.0.birds_per_tray',
                'weighings.0.weighed_at',
            ]);

        $errors = $response->json('errors');
        $this->assertSame(
            'Selecciona si el registro corresponde a una venta o una devolución.',
            $errors['operation_type'][0]
        );
        $this->assertSame(
            'La cantidad de aves por bandeja debe ser un número entero.',
            $errors['weighings.0.birds_per_tray'][0]
        );
        $this->assertSame(
            'No se recibió la fecha y hora de una pesada.',
            $errors['weighings.0.weighed_at'][0]
        );
        $this->assertStringNotContainsString('validation.', json_encode($errors, JSON_THROW_ON_ERROR));
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
                'mode' => TicketDespacho::DELIVERY_MODE_COMPANY_TRUCK,
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

    /**
     * @param  array{method_id: ?int, account_id: ?int}  $paymentDefaults
     * @return array<string, mixed>
     */
    private function configurationPayload(array $paymentDefaults): array
    {
        return [
            'default_adjustment_code' => AjustePesoMinorista::MALE_CLOSED,
            'adjustments' => [[
                'code' => AjustePesoMinorista::MALE_CLOSED,
                'additional_grams' => 0,
            ]],
            'payment_defaults' => $paymentDefaults,
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
