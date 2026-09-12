<?php

namespace Tests\Feature;

use App\Models\ReceptionSyncRecord;
use App\Models\ReceptionSyncToken;
use App\Models\Sucursal;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\ReceptionSyncJourneyReclassificationService;
use App\Services\ReceptionSyncTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ReceptionSyncJourneyReclassificationTest extends TestCase
{
    use InteractsWithAccessControl, RefreshDatabase;

    private const BASE = '/api/v1/recepcion-pollo-vivo/sync';

    private User $actor;

    private Sucursal $branch;

    private ReceptionSyncToken $token;

    private int $warehouse;

    private int $client;

    private int $cage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-10T12:00:00-05:00'));
        $this->actor = User::factory()->create();
        $this->branch = Sucursal::query()->create([
            'empresa_id' => $this->actor->empresa_id, 'codigo' => 'SYNC', 'nombre' => 'Recepción',
            'zona_horaria' => 'America/Bogota', 'estado' => 'ACTIVO',
        ]);
        $this->actor->update(['sucursal_id' => $this->branch->id]);
        $this->grantModules($this->actor, ['MODULO_RECEPCION_POLLO_VIVO']);
        $issued = app(ReceptionSyncTokenService::class)->issue($this->actor, $this->branch, 'Balanza');
        $this->token = $issued['token'];
        $this->withToken($issued['plain_text_token']);
        $this->warehouse = DB::table('almacenes')->insertGetId(['sucursal_id' => $this->branch->id, 'codigo' => 'A1', 'nombre' => 'Almacén', 'estado' => 'ACTIVO']);
        $this->cage = DB::table('tipos_java')->insertGetId(['codigo' => 'T7', 'nombre' => 'Tara 7 kg', 'peso_kg' => 7, 'estado' => 'ACTIVO']);
        $party = Tercero::query()->create(['empresa_id' => $this->actor->empresa_id, 'tipo_documento' => 'RUC', 'numero_documento' => '20111111111', 'nombre_razon_social' => 'Cliente', 'direccion' => 'Recepción', 'estado' => 'ACTIVO']);
        $party->roles()->create(['rol' => TerceroRole::CLIENT]);
        $this->client = (int) $party->id;
        $chicken = DB::table('tipos_pollo')->insertGetId(['codigo' => TipoPollo::CHICKEN_LIVE, 'nombre' => 'Pollo vivo', 'permite_despacho' => true, 'estado' => 'ACTIVO']);
        $list = DB::table('listas_precios')->insertGetId(['empresa_id' => $this->actor->empresa_id, 'codigo' => 'GENERAL', 'nombre' => 'Precio general', 'operacion' => 'VENTA', 'estado' => 'ACTIVO', 'created_by' => $this->actor->id]);
        DB::table('precios_historial')->insert(['lista_precio_id' => $list, 'tipo_pollo_id' => $chicken, 'precio_kg' => '8.7500', 'vigente_desde' => now()->subHour(), 'registrado_por' => $this->actor->id, 'created_at' => now()]);
    }

    public function test_reclassification_keeps_paid_ticket_facts_prices_balances_and_payments(): void
    {
        $operation = $this->operation('ticket');
        $response = $this->push($operation)->assertJsonPath('data.results.0.status', 'applied');
        $record = ReceptionSyncRecord::query()->findOrFail($response->json('data.results.0.record.id'));
        $before = $record->document();
        $document = DB::table('comprobantes')->first();
        $payment = DB::table('pagos')->insertGetId([
            'empresa_id' => $this->actor->empresa_id, 'tercero_id' => $this->client,
            'direccion' => 'INGRESO', 'fecha_hora' => now(), 'metodo' => 'EFECTIVO',
            'importe' => 40, 'created_by' => $this->actor->id, 'estado' => 'REGISTRADO',
        ]);
        DB::table('pago_aplicaciones')->insert(['pago_id' => $payment, 'comprobante_id' => $document->id, 'importe_aplicado' => 40]);
        DB::table('comprobantes')->where('id', $document->id)->update(['saldo_pendiente' => 712.5, 'estado' => 'PARCIAL']);
        $financialBefore = $this->financialState();

        $this->assertSame(1, $this->changeCutoff('22:00'));
        $after = $record->fresh()->document();
        $this->assertSame('2026-09-08', $after['operating_date']);
        $this->assertSame('2026-09-08', $after['payload']['operating_date']);
        $this->assertSame('22:00:00', $after['payload']['operating_cutoff']);
        $this->assertSame(2, $after['revision']);
        foreach (['id', 'uuid', 'device_id', 'kind', 'status', 'created_at'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        foreach (['weighings', 'totals', 'destination', 'local_number'] as $field) {
            $this->assertSame($before['payload'][$field], $after['payload'][$field]);
        }
        $this->assertSame($financialBefore, $this->financialState());
        $this->assertDatabaseHas('comprobantes', ['id' => $document->id, 'fecha_emision' => '2026-09-08', 'fecha_vencimiento' => '2026-09-08']);
        $this->assertSame(0, $this->changeCutoff('22:00'));
        $this->assertSame(2, $record->fresh()->revision);
    }

    public function test_first_chronological_weighing_including_voided_lines_anchors_whole_record(): void
    {
        $operation = $this->operation();
        $operation['payload']['weighings'][] = [...$operation['payload']['weighings'][0],
            'uuid' => (string) Str::uuid(), 'weighed_at' => '2026-09-09T04:30:00Z'];
        $this->push($operation)->assertJsonPath('data.results.0.status', 'applied');
        $record = ReceptionSyncRecord::query()->firstOrFail();
        $payload = $record->payload;
        $payload['weighings'][0]['status'] = 'voided';
        $payload['weighings'][0]['void_reason'] = 'Duplicada';
        $payload['weighings'] = array_reverse($payload['weighings']);
        $record->update(['status' => 'voided', 'payload' => $payload]);

        $otherActor = User::factory()->create();
        $otherBranch = Sucursal::query()->create(['empresa_id' => $otherActor->empresa_id, 'codigo' => 'OTHER', 'nombre' => 'Otra', 'zona_horaria' => 'America/Bogota', 'estado' => 'ACTIVO']);
        $other = ReceptionSyncRecord::query()->create([
            'company_id' => $otherActor->empresa_id, 'branch_id' => $otherBranch->id,
            'device_id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(), 'kind' => 'reception',
            'operating_date' => '2026-09-09', 'status' => 'active', 'revision' => 3,
            'payload' => $payload, 'created_by' => $otherActor->id,
        ]);
        $otherBefore = $other->document();

        $this->assertSame(1, $this->changeCutoff('22:00'));
        $this->assertSame('2026-09-08', $record->fresh()->operating_date->format('Y-m-d'));
        $this->assertSame('voided', $record->fresh()->status);
        $this->assertSame($payload['weighings'], $record->fresh()->payload['weighings']);
        $this->assertSame($otherBefore, $other->fresh()->document());
    }

    public function test_reclassification_preserves_dates_and_details_of_fiscal_documents(): void
    {
        $this->push($this->operation('ticket'))->assertJsonPath('data.results.0.status', 'applied');
        $document = DB::table('comprobantes')->first();
        DB::table('comprobantes')->where('id', $document->id)->update(['tipo_documento' => 'FACTURA']);
        $before = (array) DB::table('comprobantes')->where('id', $document->id)->first();
        $financialBefore = $this->financialState();

        $this->assertSame(1, $this->changeCutoff('22:00'));

        $this->assertSame('2026-09-08', ReceptionSyncRecord::query()->firstOrFail()->operating_date->format('Y-m-d'));
        $this->assertSame($before, (array) DB::table('comprobantes')->where('id', $document->id)->first());
        $this->assertSame($financialBefore, $this->financialState());
    }

    public function test_empty_historical_record_falls_back_to_created_at_in_branch_timezone(): void
    {
        $record = ReceptionSyncRecord::query()->create([
            'company_id' => $this->actor->empresa_id, 'branch_id' => $this->branch->id,
            'device_id' => $this->token->device_id, 'uuid' => (string) Str::uuid(), 'kind' => 'reception',
            'operating_date' => '2026-09-09', 'status' => 'voided', 'revision' => 1,
            'payload' => ['weighings' => [], 'legacy' => 'preserved'], 'created_by' => $this->actor->id,
            'created_at' => CarbonImmutable::parse('2026-09-09T02:30:00Z')->setTimezone(config('app.timezone')),
        ]);
        $this->changeCutoff('22:00');
        $this->assertSame('2026-09-08', $record->fresh()->operating_date->format('Y-m-d'));
        $this->assertSame('preserved', $record->fresh()->payload['legacy']);
    }

    public function test_delayed_upload_and_retry_use_server_schedule_and_current_revision(): void
    {
        $operation = $this->operation();
        $this->push($operation)->assertJsonPath('data.results.0.record.operating_date', '2026-09-09');
        $receipt = (array) DB::table('reception_sync_operations')->first();
        $this->changeCutoff('22:00');
        $this->push($operation)->assertJsonPath('data.results.0.status', 'replayed')
            ->assertJsonPath('data.results.0.record.operating_date', '2026-09-08')
            ->assertJsonPath('data.results.0.record.payload.operating_cutoff', '22:00:00')
            ->assertJsonPath('data.results.0.record.revision', 2);
        $this->assertSame($receipt, (array) DB::table('reception_sync_operations')->first());

        $edit = [...$operation, 'operation_id' => (string) Str::uuid(), 'expected_revision' => 1, 'reason' => 'Corrección'];
        $this->push($edit)->assertJsonPath('data.results.0.status', 'conflict')->assertJsonPath('data.results.0.record.revision', 2);
        $edit['operation_id'] = (string) Str::uuid();
        $edit['expected_revision'] = 2;
        $edit['payload']['weighings'][0]['birds_per_cage'] = 8;
        $this->push($edit)->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.record.operating_date', '2026-09-08')
            ->assertJsonPath('data.results.0.record.revision', 3);

        $delayed = $this->operation();
        $delayed['payload']['weighings'][] = [...$delayed['payload']['weighings'][0],
            'uuid' => (string) Str::uuid(), 'weighed_at' => '2026-09-08T22:30:00-05:00'];
        $delayed['payload']['weighings'] = array_reverse($delayed['payload']['weighings']);
        $this->push($delayed)->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.record.operating_date', '2026-09-08')
            ->assertJsonPath('data.results.0.record.payload.operating_cutoff', '22:00:00');
        $this->getJson(self::BASE.'/reports?date_from=2026-09-08&date_to=2026-09-08')->assertOk()
            ->assertJsonPath('data.record_counts.active', 2)->assertJsonPath('data.totals.weighings', 3);
        $this->assertDatabaseCount('reception_sync_records', 2);
    }

    public function test_old_snapshot_expires_and_new_snapshot_contains_current_schedule_and_records(): void
    {
        $operation = $this->operation();
        $this->push($operation)->assertJsonPath('data.results.0.status', 'applied');
        $snapshot = $this->postJson(self::BASE.'/snapshots')->assertCreated()->json('data.id');
        $this->getJson(self::BASE.'/snapshots/'.$snapshot.'?limit=1')->assertOk()->assertJsonPath('data.has_more', true);
        $oldItems = DB::table('reception_sync_snapshot_items')->where('snapshot_id', $snapshot)->count();
        $this->changeCutoff('22:00');
        $this->getJson(self::BASE.'/snapshots/'.$snapshot.'?after=1')->assertStatus(410);
        $this->assertSame($oldItems, DB::table('reception_sync_snapshot_items')->where('snapshot_id', $snapshot)->count());
        $this->getJson(self::BASE.'/snapshots')->assertOk()->assertJsonCount(0, 'data.snapshots');
        $new = $this->postJson(self::BASE.'/snapshots')->assertCreated()->json('data.id');
        $items = collect($this->getJson(self::BASE.'/snapshots/'.$new)->assertOk()->json('data.items'));
        $this->assertSame('22:00:00', $items->firstWhere('entity', 'company')['data']['operating_cutoff']);
        $record = $items->firstWhere('key', 'offline:'.$operation['entity_id'])['data'];
        $this->assertSame('2026-09-08', $record['operating_date']);
        $this->assertSame(2, $record['revision']);
    }

    public function test_exact_cutoff_and_midnight_are_classified_in_the_branch_timezone(): void
    {
        $this->changeCutoff('22:00');
        $boundary = $this->operation();
        $boundary['payload']['weighings'][0]['weighed_at'] = '2026-09-09T03:00:00Z';
        $this->push($boundary)->assertJsonPath('data.results.0.record.operating_date', '2026-09-09');
        $this->changeCutoff('00:00');
        $midnight = $this->operation();
        $midnight['payload']['weighings'][0]['weighed_at'] = '2026-09-09T05:00:00Z';
        $this->push($midnight)->assertJsonPath('data.results.0.record.operating_date', '2026-09-10');
    }

    public function test_replaying_a_previous_conflict_returns_reclassified_record_without_writing(): void
    {
        $operation = $this->operation();
        $this->push($operation)->assertJsonPath('data.results.0.status', 'applied');
        $conflict = [...$operation, 'operation_id' => (string) Str::uuid()];
        $this->push($conflict)->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.record.operating_date', '2026-09-09');
        $this->changeCutoff('22:00');
        $this->push($conflict)->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.replayed', true)
            ->assertJsonPath('data.results.0.record.operating_date', '2026-09-08')
            ->assertJsonPath('data.results.0.record.revision', 2);
        $this->assertDatabaseCount('reception_sync_operations', 2);
        $this->assertDatabaseCount('reception_sync_records', 1);
    }

    private function changeCutoff(string $cutoff): int
    {
        return DB::transaction(function () use ($cutoff): int {
            DB::table('empresas')->where('id', $this->actor->empresa_id)->lockForUpdate()->firstOrFail();
            DB::table('empresas')->where('id', $this->actor->empresa_id)->update(['hora_corte_operativo' => $cutoff.':00']);

            return app(ReceptionSyncJourneyReclassificationService::class)->reclassify((int) $this->actor->empresa_id, $cutoff);
        });
    }

    private function financialState(): array
    {
        return [
            'document' => (array) DB::table('comprobantes')->first(['id', 'total', 'subtotal', 'saldo_pendiente', 'estado']),
            'details' => DB::table('comprobante_detalles')->get()->map(fn ($row) => (array) $row)->all(),
            'links' => DB::table('reception_sync_financial_links')->get()->map(fn ($row) => (array) $row)->all(),
            'payments' => DB::table('pagos')->get()->map(fn ($row) => (array) $row)->all(),
            'applications' => DB::table('pago_aplicaciones')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function push(array $operation): TestResponse
    {
        return $this->postJson(self::BASE.'/push', ['operations' => [$operation]])->assertOk();
    }

    private function operation(string $kind = 'reception'): array
    {
        return [
            'operation_id' => (string) Str::uuid(), 'entity_id' => (string) Str::uuid(), 'kind' => $kind,
            'action' => 'upsert', 'expected_revision' => 0,
            'payload' => [
                'operating_date' => '2026-09-09', 'operating_cutoff' => '21:00:00',
                'lane' => $kind === 'ticket' ? 5 : 1, 'destination_id' => $kind === 'ticket' ? $this->client : $this->warehouse,
                'local_number' => 'RURAL-0001',
                'weighings' => [[
                    'uuid' => (string) Str::uuid(), 'sex' => 'MACHO', 'cage_type_id' => $this->cage,
                    'cage_weight_kg' => 7, 'birds_per_cage' => 7, 'cage_count' => 2,
                    'read_weight_kg' => 100, 'weight_source' => 'MANUAL', 'weighed_at' => '2026-09-08T21:30:00-05:00',
                ]],
            ],
        ];
    }
}
