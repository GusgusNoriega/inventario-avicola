<?php

namespace Tests\Feature;

use App\Models\ReceptionSyncToken;
use App\Models\Sucursal;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\FinancialCounterpartySummaryService;
use App\Services\ReceptionSyncTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ReceptionSyncApiTest extends TestCase
{
    use InteractsWithAccessControl, RefreshDatabase;

    private const BASE = '/api/v1/recepcion-pollo-vivo/sync';

    private User $actor;

    private Sucursal $branch;

    private ReceptionSyncToken $token;

    private int $warehouse;

    private int $client;

    private int $cage;

    private int $chickenType;

    private int $generalPrice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10T12:00:00-05:00'));
        $this->actor = User::factory()->create();
        $this->branch = Sucursal::query()->create(['empresa_id' => $this->actor->empresa_id, 'codigo' => 'SYNC', 'nombre' => 'Recepción rural', 'zona_horaria' => 'America/Bogota', 'estado' => 'ACTIVO']);
        $this->actor->update(['sucursal_id' => $this->branch->id]);
        $this->grantModules($this->actor, ['MODULO_RECEPCION_POLLO_VIVO']);
        $issued = app(ReceptionSyncTokenService::class)->issue($this->actor, $this->branch, 'Balanza rural');
        $this->token = $issued['token'];
        $this->withToken($issued['plain_text_token']);
        $this->warehouse = DB::table('almacenes')->insertGetId(['sucursal_id' => $this->branch->id, 'codigo' => 'A1', 'nombre' => 'Almacén propio', 'estado' => 'ACTIVO']);
        $this->cage = DB::table('tipos_java')->insertGetId(['codigo' => 'T7', 'nombre' => 'Tara 7 kg', 'peso_kg' => 7, 'estado' => 'ACTIVO']);
        $party = Tercero::query()->create(['empresa_id' => $this->actor->empresa_id, 'tipo_documento' => 'RUC', 'numero_documento' => '20111111111', 'nombre_razon_social' => 'Cliente rural', 'direccion' => 'Recepción rural', 'estado' => 'ACTIVO']);
        $party->roles()->create(['rol' => TerceroRole::CLIENT]);
        $this->client = (int) $party->id;
        $this->chickenType = DB::table('tipos_pollo')->insertGetId(['codigo' => TipoPollo::CHICKEN_LIVE, 'nombre' => 'Pollo vivo', 'permite_despacho' => true, 'estado' => 'ACTIVO']);
        $list = DB::table('listas_precios')->insertGetId(['empresa_id' => $this->actor->empresa_id, 'tercero_id' => null, 'codigo' => 'GENERAL', 'nombre' => 'Precio general', 'operacion' => 'VENTA', 'estado' => 'ACTIVO', 'created_by' => $this->actor->id]);
        $this->generalPrice = DB::table('precios_historial')->insertGetId(['lista_precio_id' => $list, 'tipo_pollo_id' => $this->chickenType, 'precio_kg' => '8.7500', 'vigente_desde' => now()->subHour(), 'registrado_por' => $this->actor->id, 'created_at' => now()]);
    }

    public function test_historical_reception_and_ticket_are_atomic_idempotent_and_isolated(): void
    {
        $reception = $this->operation();
        $ticket = $this->operation('ticket');
        $response = $this->postJson(self::BASE.'/push', ['operations' => [$reception, $ticket]])->assertOk()
            ->assertJsonPath('data.has_errors', false)->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.record.payload.totals.net_weight_kg', 86)
            ->assertJsonPath('data.results.0.record.operating_date', '2026-09-08');
        $this->postJson(self::BASE.'/push', ['operations' => [$reception, $ticket]])->assertOk()->assertJsonPath('data.results.0.status', 'replayed');
        $this->assertDatabaseCount('reception_sync_records', 2);
        $this->assertDatabaseCount('reception_sync_operations', 2);
        foreach (['jornadas_operativas', 'recepciones_pollo_vivo', 'pesadas_recepcion_pollo_vivo', 'tickets_despacho', 'pesadas', 'movimientos_javas', 'inventarios_javas', 'movimientos_inventario', 'movimiento_detalles', 'pagos'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseCount('reception_sync_financial_links', 1);
        $this->assertDatabaseHas('comprobantes', ['tercero_id' => $this->client, 'operacion' => 'VENTA', 'naturaleza' => 'CARGO', 'total' => 752.50, 'saldo_pendiente' => 752.50]);
        $this->getJson(self::BASE.'/records/'.$reception['entity_id'])->assertOk()->assertJsonPath('data.uuid', $reception['entity_id']);
        $this->assertSame(1, $response->json('data.results.0.record.revision'));
    }

    public function test_failed_operation_does_not_discard_valid_neighbors_or_duplicate_on_retry(): void
    {
        $bad = $this->operation();
        $bad['payload']['weighings'][0]['read_weight_kg'] = 3;
        $good = $this->operation();
        $this->postJson(self::BASE.'/push', ['operations' => [$bad, $good]])->assertOk()
            ->assertJsonPath('data.has_errors', true)->assertJsonPath('data.results.0.http_status', 422)
            ->assertJsonPath('data.results.1.status', 'applied');
        $this->assertDatabaseCount('reception_sync_records', 1);
        $this->postJson(self::BASE.'/push', ['operations' => [$bad, $good]])->assertOk()
            ->assertJsonPath('data.results.0.replayed', true)->assertJsonPath('data.results.1.status', 'replayed');
        $bad['payload']['weighings'][0]['read_weight_kg'] = 100;
        $this->postJson(self::BASE.'/push', ['operations' => [$bad]])->assertOk()->assertJsonPath('data.results.0.http_status', 409);
        $bad['operation_id'] = (string) Str::uuid();
        $this->postJson(self::BASE.'/push', ['operations' => [$bad]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');
    }

    public function test_revision_prevents_overwriting_another_device_and_void_keeps_history(): void
    {
        $original = $this->operation('ticket');
        $this->postJson(self::BASE.'/push', ['operations' => [$original]])->assertOk();
        $otherDevice = app(ReceptionSyncTokenService::class)->issue($this->actor, $this->branch, 'Segundo equipo');
        $this->withToken($otherDevice['plain_text_token']);
        $edit = [...$original, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1, 'reason' => 'Corrección de aves'];
        $edit['payload']['weighings'][0]['birds_per_cage'] = 8;
        $this->postJson(self::BASE.'/push', ['operations' => [$edit]])->assertOk()->assertJsonPath('data.results.0.record.revision', 2);
        $edit['operation_id'] = (string) Str::uuid();
        $this->postJson(self::BASE.'/push', ['operations' => [$edit]])->assertOk()->assertJsonPath('data.results.0.status', 'conflict')->assertJsonPath('data.results.0.record.revision', 2);
        $void = ['operation_id' => (string) Str::uuid(), 'entity_id' => $original['entity_id'], 'kind' => 'ticket', 'action' => 'void', 'expected_revision' => 2, 'reason' => 'Cancelado por el cliente'];
        $this->postJson(self::BASE.'/push', ['operations' => [$void]])->assertOk()->assertJsonPath('data.results.0.record.status', 'voided')->assertJsonCount(1, 'data.results.0.record.payload.weighings');
        $this->getJson(self::BASE.'/reports?date_from=2026-09-08&date_to=2026-09-08')->assertOk()->assertJsonPath('data.totals.birds', 0)->assertJsonPath('data.record_counts.voided', 1);
        $this->assertDatabaseHas('comprobantes', ['tercero_id' => $this->client, 'estado' => 'ANULADO']);
    }

    public function test_snapshot_tare_is_preserved_and_inactive_catalog_entries_work_for_delayed_upload(): void
    {
        DB::table('tipos_java')->where('id', $this->cage)->update(['peso_kg' => 9, 'estado' => 'INACTIVO']);
        DB::table('terceros')->where('id', $this->client)->update(['estado' => 'INACTIVO']);
        $this->postJson(self::BASE.'/push', ['operations' => [$this->operation('ticket')]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')->assertJsonPath('data.results.0.record.payload.weighings.0.cage_weight_kg', 7)
            ->assertJsonPath('data.results.0.record.payload.totals.net_weight_kg', 86);
    }

    public function test_scoped_catalogs_and_records_never_cross_companies_or_branches(): void
    {
        $otherUser = User::factory()->create();
        $otherBranch = Sucursal::query()->create(['empresa_id' => $otherUser->empresa_id, 'codigo' => 'OTHER', 'nombre' => 'Otra sucursal', 'zona_horaria' => 'America/Bogota', 'estado' => 'ACTIVO']);
        $foreignWarehouse = DB::table('almacenes')->insertGetId(['sucursal_id' => $otherBranch->id, 'codigo' => 'A2', 'nombre' => 'Otro almacén', 'estado' => 'ACTIVO']);
        $bad = $this->operation();
        $bad['payload']['destination_id'] = $foreignWarehouse;
        $this->postJson(self::BASE.'/push', ['operations' => [$bad]])->assertOk()->assertJsonPath('data.results.0.http_status', 422);
        $good = $this->operation();
        $this->postJson(self::BASE.'/push', ['operations' => [$good]])->assertOk();
        $otherUser->update(['sucursal_id' => $otherBranch->id]);
        $this->grantModules($otherUser, ['MODULO_RECEPCION_POLLO_VIVO']);
        $token = app(ReceptionSyncTokenService::class)->issue($otherUser, $otherBranch, 'Ajeno');
        $this->withToken($token['plain_text_token']);
        $this->getJson(self::BASE.'/records/'.$good['entity_id'])->assertNotFound();
        $this->getJson(self::BASE.'/records?company_id='.$this->actor->empresa_id)->assertOk()->assertJsonCount(0, 'data.records');
        $void = ['operation_id' => (string) Str::uuid(), 'entity_id' => $good['entity_id'], 'kind' => 'reception', 'action' => 'void', 'expected_revision' => 1, 'reason' => 'Intento'];
        $this->postJson(self::BASE.'/push', ['operations' => [$void]])->assertOk()->assertJsonPath('data.results.0.record', null);
    }

    public function test_partial_weighing_void_affects_totals_and_cannot_delete_or_reuse_weighings(): void
    {
        $op = $this->operation('ticket');
        $op['payload']['weighings'][] = [...$op['payload']['weighings'][0], 'uuid' => (string) Str::uuid(), 'sex' => 'HEMBRA'];
        $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk();
        $edit = [...$op, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1, 'reason' => 'Anular pesada duplicada'];
        $edit['payload']['weighings'][1]['status'] = 'voided';
        $edit['payload']['weighings'][1]['void_reason'] = 'Duplicada';
        $this->postJson(self::BASE.'/push', ['operations' => [$edit]])->assertOk()->assertJsonPath('data.results.0.record.payload.totals.birds', 14);
        $drop = [...$edit, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 2];
        array_pop($drop['payload']['weighings']);
        $this->postJson(self::BASE.'/push', ['operations' => [$drop]])->assertOk()->assertJsonPath('data.results.0.http_status', 422);
        $duplicate = [...$op, 'entity_id' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid()];
        $this->postJson(self::BASE.'/push', ['operations' => [$duplicate]])->assertOk()->assertJsonPath('data.results.0.http_status', 422);
        $this->getJson(self::BASE.'/reports?date_from=2026-09-08&date_to=2026-09-08')->assertOk()->assertJsonPath('data.totals.birds', 14)->assertJsonPath('data.by_client.0.name', 'Cliente rural');
    }

    public function test_validation_rejects_bad_dates_lane_mismatch_and_unbounded_requests(): void
    {
        $future = $this->operation();
        $future['payload']['operating_date'] = '2026-09-12';
        $future['payload']['weighings'][0]['weighed_at'] = '2026-09-12T10:00:00-05:00';
        $lane = $this->operation();
        $lane['payload']['lane'] = 2;
        $offset = $this->operation();
        $offset['payload']['weighings'][0]['weighed_at'] = '2026-09-08 10:00:00';
        $injected = $this->operation();
        $injected['payload']['company_id'] = 999;
        $this->postJson(self::BASE.'/push', ['operations' => [$future, $lane, $offset, $injected, ['invalid' => true], null]])->assertOk()
            ->assertJsonPath('data.results.0.http_status', 422)->assertJsonPath('data.results.1.http_status', 422)
            ->assertJsonPath('data.results.2.http_status', 422)->assertJsonPath('data.results.3.http_status', 422)
            ->assertJsonPath('data.results.4.http_status', 422)->assertJsonPath('data.results.5.http_status', 422);
        $this->postJson(self::BASE.'/push', ['operations' => array_fill(0, 51, $this->operation())])->assertUnprocessable();
        $this->getJson(self::BASE.'/records?limit=201')->assertUnprocessable();
        $this->getJson(self::BASE.'/reports?date_from=2020-01-01&date_to=2026-01-01')->assertUnprocessable();
        $this->assertDatabaseCount('reception_sync_records', 0);
    }

    public function test_operating_cutoff_and_offset_are_used_instead_of_upload_day(): void
    {
        $op = $this->operation();
        $op['payload']['weighings'][0]['weighed_at'] = '2026-09-08T02:30:00Z';
        $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');
    }

    public function test_bearer_sync_does_not_require_csrf_even_with_frontend_origin_and_pagination_resumes(): void
    {
        $this->withHeader('Origin', config('app.url'));
        $this->postJson(self::BASE.'/push', ['operations' => [$this->operation(), $this->operation()]])->assertOk()->assertJsonPath('data.has_errors', false);
        $first = $this->getJson(self::BASE.'/records?limit=1')->assertOk()->assertJsonPath('data.has_more', true)->assertJsonCount(1, 'data.records');
        $this->getJson(self::BASE.'/records?limit=1&after='.$first->json('data.next_after'))->assertOk()->assertJsonPath('data.has_more', false)->assertJsonCount(1, 'data.records');
    }

    public function test_real_snapshot_endpoints_support_full_roundtrip(): void
    {
        $op = $this->operation('ticket');
        $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk();
        $manifest = $this->postJson(self::BASE.'/snapshots')->assertCreated();
        $page = $this->getJson(self::BASE.'/snapshots/'.$manifest->json('data.id'))->assertOk();
        $record = collect($page->json('data.items'))->firstWhere('key', 'offline:'.$op['entity_id']);
        $this->assertNotNull($record);
        $this->assertSame(14, $record['data']['payload']['totals']['birds']);
        $this->assertTrue($record['data']['editable']);
        $this->getJson(self::BASE.'/snapshots')->assertOk()->assertJsonPath('data.snapshots.0.id', $manifest->json('data.id'));
        $this->deleteJson(self::BASE.'/snapshots/'.$manifest->json('data.id'))->assertNoContent();
        $this->getJson(self::BASE.'/snapshots/'.$manifest->json('data.id'))->assertNotFound();
    }

    public function test_reception_without_prices_never_creates_debt_and_ticket_failure_rolls_back_everything(): void
    {
        DB::table('precios_historial')->where('id', $this->generalPrice)->update(['precio_kg' => 0]);
        $ticket = $this->operation('ticket');
        $reception = $this->operation();
        $this->postJson(self::BASE.'/push', ['operations' => [$ticket, $reception]])->assertOk()
            ->assertJsonPath('data.results.0.http_status', 422)->assertJsonPath('data.results.1.status', 'applied');
        $this->assertDatabaseCount('reception_sync_records', 1);
        $this->assertDatabaseCount('reception_sync_weighing_keys', 1);
        $this->assertDatabaseCount('reception_sync_financial_links', 0);
        $this->assertDatabaseCount('comprobantes', 0);
        $this->assertDatabaseCount('auditoria_eventos', 0);
        DB::table('precios_historial')->where('id', $this->generalPrice)->update(['precio_kg' => '8.7500']);
        $this->postJson(self::BASE.'/push', ['operations' => [$ticket]])->assertOk()->assertJsonPath('data.results.0.http_status', 422)->assertJsonPath('data.results.0.replayed', true);
        $ticket['operation_id'] = (string) Str::uuid();
        $this->postJson(self::BASE.'/push', ['operations' => [$ticket]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');
        $this->assertDatabaseCount('comprobantes', 1);
    }

    public function test_server_uses_current_client_price_and_preserves_it_on_retry_and_weight_correction(): void
    {
        $specific = $this->clientPrice($this->client, '10.2500');
        DB::table('precios_historial')->insert([
            'lista_precio_id' => DB::table('precios_historial')->where('id', $specific)->value('lista_precio_id'),
            'tipo_pollo_id' => $this->chickenType, 'precio_kg' => '99.0000',
            'vigente_desde' => now()->addDay(), 'registrado_por' => $this->actor->id, 'created_at' => now(),
        ]);
        $op = $this->operation('ticket');
        $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');
        $this->assertDatabaseHas('comprobantes', ['total' => 881.50, 'fecha_emision' => '2026-09-08']);
        $this->assertDatabaseHas('reception_sync_financial_links', ['price_history_id' => $specific, 'price_source' => 'CLIENTE', 'price_kg' => 10.25]);
        DB::table('precios_historial')->where('id', $specific)->update(['precio_kg' => 30]);
        $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk()->assertJsonPath('data.results.0.status', 'replayed');
        $edit = [...$op, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1, 'reason' => 'Corregir peso leído'];
        $edit['payload']['weighings'][0]['read_weight_kg'] = 110;
        $this->postJson(self::BASE.'/push', ['operations' => [$edit]])->assertOk()->assertJsonPath('data.results.0.record.payload.totals.net_weight_kg', 96);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseHas('comprobantes', ['total' => 984.00, 'saldo_pendiente' => 984.00]);
        $this->assertDatabaseHas('comprobante_detalles', ['peso_neto_kg' => 96, 'precio_kg' => 10.25]);
        $this->getJson(self::BASE.'/reports?date_from=2026-09-08&date_to=2026-09-08')->assertOk()->assertJsonPath('data.totals.birds', 14)->assertJsonPath('data.totals.net_weight_kg', 96);
    }

    public function test_expired_client_prices_fall_back_to_effective_general_price(): void
    {
        $specific = $this->clientPrice($this->client, '1.0000');
        DB::table('precios_historial')->where('id', $specific)->update(['vigente_desde' => now()->subDays(10), 'vigente_hasta' => now()->subDays(3)]);
        $this->postJson(self::BASE.'/push', ['operations' => [$this->operation('ticket')]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');
        $this->assertDatabaseHas('comprobantes', ['total' => 752.50]);
        $this->assertDatabaseHas('reception_sync_financial_links', ['price_source' => 'GENERAL']);
    }

    public function test_client_change_revalues_the_same_document_and_missing_price_rejects_correction_atomically(): void
    {
        $op = $this->operation('ticket');
        $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');
        $client = Tercero::query()->create(['empresa_id' => $this->actor->empresa_id, 'tipo_documento' => 'RUC', 'numero_documento' => '20222222222', 'nombre_razon_social' => 'Cliente corregido', 'direccion' => 'Otra dirección', 'estado' => 'ACTIVO']);
        $client->roles()->create(['rol' => TerceroRole::CLIENT]);
        $edit = [...$op, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1, 'reason' => 'Cliente incorrecto'];
        $edit['payload']['destination_id'] = (int) $client->id;
        DB::table('precios_historial')->where('id', $this->generalPrice)->update(['vigente_hasta' => now()->subMinute()]);
        $this->postJson(self::BASE.'/push', ['operations' => [$edit]])->assertOk()->assertJsonPath('data.results.0.http_status', 422);
        $this->getJson(self::BASE.'/records/'.$op['entity_id'])->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.payload.destination_id', $this->client);
        $this->assertDatabaseHas('comprobantes', ['tercero_id' => $this->client, 'total' => 752.50]);
        $this->clientPrice((int) $client->id, '12.0000');
        $edit['operation_id'] = (string) Str::uuid();
        $this->postJson(self::BASE.'/push', ['operations' => [$edit]])->assertOk()->assertJsonPath('data.results.0.record.revision', 2);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->assertDatabaseHas('comprobantes', ['tercero_id' => $client->id, 'total' => 1032.00, 'contraparte_nombre_snapshot' => 'Cliente corregido']);
        $oldBalance = app(FinancialCounterpartySummaryService::class)->forCustomer((int) $this->actor->empresa_id, $this->client);
        $this->assertSame('0.00', $oldBalance['pending']);
    }

    public function test_debt_is_visible_and_payable_in_finance_but_paid_tickets_cannot_be_changed_offline(): void
    {
        $op = $this->operation('ticket');
        $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');
        $document = (int) DB::table('comprobantes')->value('id');
        $this->makeAdministrator($this->actor);
        Sanctum::actingAs($this->actor, ['api']);
        $this->getJson('/api/v1/finanzas/cartera?lado=CXC&cliente_id='.$this->client)->assertOk()
            ->assertJsonPath('data.0.id', $document)->assertJsonPath('data.0.saldo_pendiente', '752.50')->assertJsonPath('resumen.saldo_neto', '752.50');
        $this->getJson('/api/v1/finanzas/clientes/'.$this->client.'/resumen')->assertOk()->assertJsonPath('data.pending', '752.50');
        $entity = DB::table('entidades_financieras')->insertGetId(['empresa_id' => $this->actor->empresa_id, 'tipo' => 'PROPIA', 'razon_social' => 'Empresa', 'estado' => 'ACTIVO', 'created_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $account = DB::table('cuentas_financieras')->insertGetId(['entidad_financiera_id' => $entity, 'tipo' => 'CAJA', 'alias' => 'Caja', 'moneda' => 'PEN', 'estado' => 'ACTIVO', 'created_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/finanzas/movimientos', [
            'idempotency_key' => (string) Str::uuid(), 'tipo' => 'COBRO_CLIENTE', 'fecha_hora' => now()->toDateTimeString(),
            'cliente_id' => $this->client, 'cuenta_destino_id' => $account,
            'metodo_pago_id' => DB::table('metodos_pago')->where('codigo', 'EFECTIVO')->value('id'),
            'moneda' => 'PEN', 'importe' => '40.00',
            'aplicaciones' => [['lado' => 'CXC', 'comprobante_id' => $document, 'importe_aplicado' => '40.00']],
        ])->assertCreated();
        $this->getJson('/api/v1/finanzas/clientes/'.$this->client.'/resumen')->assertOk()->assertJsonPath('data.pending', '712.50');
        $edit = [...$op, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1, 'reason' => 'Peso corregido'];
        $edit['payload']['weighings'][0]['read_weight_kg'] = 110;
        $void = ['operation_id' => (string) Str::uuid(), 'entity_id' => $op['entity_id'], 'kind' => 'ticket', 'action' => 'void', 'expected_revision' => 1, 'reason' => 'Cancelar'];
        $this->postJson(self::BASE.'/push', ['operations' => [$edit, $void]])->assertOk()->assertJsonPath('data.results.0.http_status', 422)->assertJsonPath('data.results.1.http_status', 422);
        $this->getJson(self::BASE.'/records/'.$op['entity_id'])->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.status', 'active')->assertJsonPath('data.payload.totals.net_weight_kg', 86);
        $this->assertDatabaseHas('comprobantes', ['id' => $document, 'total' => 752.50, 'saldo_pendiente' => 712.50, 'estado' => 'PARCIAL']);
        $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk()->assertJsonPath('data.results.0.status', 'replayed');
    }

    public function test_sync_never_returns_prices_balances_or_documents_and_rejects_client_supplied_prices(): void
    {
        $op = $this->operation('ticket');
        $response = $this->postJson(self::BASE.'/push', ['operations' => [$op]])->assertOk()->assertJsonPath('data.results.0.status', 'applied');
        $snapshot = $this->postJson(self::BASE.'/snapshots')->assertCreated();
        $page = $this->getJson(self::BASE.'/snapshots/'.$snapshot->json('data.id'))->assertOk();
        foreach ([$response->getContent(), $page->getContent(), $this->getJson(self::BASE.'/records')->getContent()] as $json) {
            foreach (['"price_kg"', '"precio_kg"', '"saldo_pendiente"', '"document_id"', '"financial_links"'] as $field) {
                $this->assertStringNotContainsString($field, $json);
            }
        }
        $injected = $this->operation('ticket');
        $injected['payload']['price_kg'] = '0.01';
        $this->postJson(self::BASE.'/push', ['operations' => [$injected]])->assertOk()->assertJsonPath('data.results.0.http_status', 422);
        $this->assertDatabaseCount('comprobantes', 1);
        $this->getJson(self::BASE.'/status')->assertOk()->assertJsonPath('data.capabilities.server_client_debt', true)->assertJsonPath('data.capabilities.client_financial_data', false);
    }

    private function clientPrice(int $clientId, string $price): int
    {
        $list = DB::table('listas_precios')->insertGetId(['empresa_id' => $this->actor->empresa_id, 'tercero_id' => $clientId, 'codigo' => 'CLIENTE-'.$clientId, 'nombre' => 'Precio cliente', 'operacion' => 'VENTA', 'estado' => 'ACTIVO', 'created_by' => $this->actor->id]);

        return DB::table('precios_historial')->insertGetId(['lista_precio_id' => $list, 'tipo_pollo_id' => $this->chickenType, 'precio_kg' => $price, 'vigente_desde' => now()->subMinute(), 'registrado_por' => $this->actor->id, 'created_at' => now()]);
    }

    private function operation(string $kind = 'reception'): array
    {
        return [
            'operation_id' => (string) Str::uuid(), 'entity_id' => (string) Str::uuid(), 'kind' => $kind,
            'action' => 'upsert', 'expected_revision' => 0,
            'payload' => [
                'operating_date' => '2026-09-08', 'operating_cutoff' => '21:00:00',
                'lane' => $kind === 'ticket' ? 5 : 1, 'destination_id' => $kind === 'ticket' ? $this->client : $this->warehouse,
                'local_number' => 'RURAL-0001',
                'weighings' => [[
                    'uuid' => (string) Str::uuid(), 'sex' => 'MACHO', 'cage_type_id' => $this->cage,
                    'cage_weight_kg' => 7, 'birds_per_cage' => 7, 'cage_count' => 2,
                    'read_weight_kg' => 100, 'weight_source' => 'MANUAL', 'weighed_at' => '2026-09-08T10:00:00-05:00',
                ]],
            ],
        ];
    }
}
