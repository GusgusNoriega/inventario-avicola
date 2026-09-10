<?php

namespace Tests\Feature;

use App\Models\JornadaOperativa;
use App\Models\Pesada;
use App\Models\PesadaRecepcionPolloVivo;
use App\Models\RecepcionPolloVivo;
use App\Models\RecepcionPolloVivoTicket;
use App\Models\ReceptionSyncRecord;
use App\Models\ReceptionSyncToken;
use App\Models\Sucursal;
use App\Models\TicketDespacho;
use App\Models\User;
use App\Services\ReceptionSyncSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReceptionSyncSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Sucursal $branch;

    private ReceptionSyncToken $token;

    private ReceptionSyncSnapshotService $snapshots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->branch = Sucursal::query()->create([
            'empresa_id' => $this->user->empresa_id, 'codigo' => 'SNAPSHOT', 'nombre' => 'Recepción local',
            'direccion' => 'Almacén', 'zona_horaria' => 'America/Bogota', 'estado' => 'ACTIVO',
        ]);
        $this->token = new ReceptionSyncToken([
            'empresa_id' => $this->user->empresa_id, 'sucursal_id' => $this->branch->id,
            'user_id' => $this->user->id, 'device_id' => (string) Str::uuid(),
        ]);
        $this->snapshots = app(ReceptionSyncSnapshotService::class);
    }

    public function test_complete_snapshot_includes_only_module_data_inactive_catalogs_and_voided_lines(): void
    {
        $fixture = $this->seedModuleRows();
        $tables = ['inventarios_javas', 'movimientos_javas', 'movimientos_inventario', 'movimiento_detalles', 'comprobantes', 'tickets_despacho', 'pesadas'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        $snapshot = $this->snapshots->create($this->token, $this->branch);
        $items = collect($this->allItems($snapshot['id']));
        $records = $items->where('entity', 'record')->keyBy('key');

        $this->assertCount(3, $records);
        $reception = $records->get('web:reception:'.$fixture['weighing']);
        $ticket = $records->get('web:ticket:'.$fixture['ticket']);
        $offline = $records->get('offline:'.$fixture['offline']);
        $this->assertFalse($reception['data']['editable']);
        $this->assertSame('voided', $reception['data']['status']);
        $this->assertSame('7.000', $reception['data']['payload']['weighings'][0]['cage_weight_kg']);
        $this->assertSame('ALMACEN', $reception['data']['payload']['destination']['type']);
        $this->assertCount(2, $ticket['data']['payload']['weighings']);
        $this->assertSame('voided', $ticket['data']['payload']['weighings'][1]['status']);
        $this->assertSame(35, $ticket['data']['payload']['totals']['birds']);
        $this->assertTrue($offline['data']['editable']);
        $this->assertNotSame($this->token->device_id, $offline['data']['device_id']);
        $this->assertFalse($items->firstWhere('entity', 'client')['data']['active']);
        $this->assertFalse($items->where('entity', 'cage_type')->firstWhere('key', (string) $fixture['cage'])['data']['active']);
        $this->assertSame(1, $items->where('entity', 'journey')->count());
        $serialized = json_encode($items->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Financial-only outsider', $serialized);
        $this->assertStringNotContainsString('precio_kg', $serialized);
        $this->assertSame($before->all(), collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all());
    }

    public function test_pages_are_resumable_complete_and_unchanged_after_source_mutation(): void
    {
        for ($index = 0; $index < 213; $index++) {
            DB::table('tipos_java')->insert([
                'codigo' => 'PAGE_'.$index, 'nombre' => 'Tara '.$index, 'peso_kg' => 6.8,
                'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $expectedCages = DB::table('tipos_java')->count();
        $snapshot = $this->snapshots->create($this->token, $this->branch);
        $first = $this->snapshots->page($this->token, $snapshot['id'], 0, 200);
        $this->assertCount(200, $first['items']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(200, $first['next_after']);
        DB::table('tipos_java')->update(['nombre' => 'Changed after snapshot', 'peso_kg' => 100]);
        DB::table('tipos_java')->insert([
            'codigo' => 'NEW', 'nombre' => 'Created later', 'peso_kg' => 2,
            'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $retry = $this->snapshots->page($this->token, $snapshot['id'], 0, 200);
        $this->assertSame($first, $retry);
        $items = $this->allItems($snapshot['id']);
        $this->assertCount($snapshot['total_items'], $items);
        $this->assertCount($expectedCages, collect($items)->where('entity', 'cage_type'));
        $this->assertSame(range(1, $snapshot['total_items']), array_column($items, 'sequence'));
        $this->assertStringNotContainsString('Created later', json_encode($items));
        $this->assertStringNotContainsString('Changed after snapshot', json_encode($items));
        $last = $this->snapshots->page($this->token, $snapshot['id'], $snapshot['total_items']);
        $this->assertFalse($last['has_more']);
        $this->assertNull($last['next_after']);
        $this->assertSame([], $last['items']);
    }

    public function test_snapshot_does_not_include_rows_from_another_branch_or_company(): void
    {
        $fixture = $this->seedModuleRows();
        foreach ([$this->user, User::factory()->create()] as $otherUser) {
            $branch = Sucursal::query()->create([
                'empresa_id' => $otherUser->empresa_id, 'codigo' => 'PRIVATE-'.$otherUser->id,
                'nombre' => 'PRIVATE BRANCH', 'zona_horaria' => 'America/Bogota', 'estado' => 'ACTIVO',
            ]);
            $journey = JornadaOperativa::query()->create([
                'sucursal_id' => $branch->id, 'fecha_operativa' => '2025-01-01', 'estado' => 'CERRADA',
                'abierta_por' => $otherUser->id, 'inicio_at' => '2024-12-31 21:00:00', 'cierre_programado_at' => '2025-01-01 21:00:00',
            ]);
            $reception = RecepcionPolloVivo::query()->create([
                'jornada_id' => $journey->id, 'origen' => 'PRIVATE RECEPTION', 'estado' => 'ABIERTA', 'created_by' => $otherUser->id,
            ]);
            $weighing = PesadaRecepcionPolloVivo::query()->findOrFail($fixture['weighing'])->replicate();
            $weighing->recepcion_id = $reception->id;
            $weighing->idempotency_key = (string) Str::uuid();
            $weighing->save();
            ReceptionSyncRecord::query()->create([
                'company_id' => $otherUser->empresa_id, 'branch_id' => $branch->id, 'device_id' => (string) Str::uuid(),
                'uuid' => (string) Str::uuid(), 'kind' => 'ticket', 'operating_date' => '2025-02-01', 'status' => 'active',
                'revision' => 1, 'payload' => ['notes' => 'PRIVATE OFFLINE'], 'created_by' => $otherUser->id,
            ]);
        }
        $snapshot = $this->snapshots->create($this->token, $this->branch);
        $items = collect($this->allItems($snapshot['id']));
        $this->assertCount(3, $items->where('entity', 'record'));
        $this->assertCount(1, $items->where('entity', 'journey'));
        $this->assertStringNotContainsString('PRIVATE', json_encode($items->all(), JSON_THROW_ON_ERROR));
    }

    public function test_snapshot_scope_expiration_limits_and_release(): void
    {
        $first = $this->snapshots->create($this->token, $this->branch);
        $this->snapshots->create($this->token, $this->branch);
        $this->snapshots->create($this->token, $this->branch);
        $this->assertHttpStatus(429, fn () => $this->snapshots->create($this->token, $this->branch));
        foreach (['empresa_id', 'sucursal_id', 'device_id'] as $field) {
            $other = clone $this->token;
            $other->{$field} = $field === 'device_id' ? (string) Str::uuid() : 999999;
            $this->assertHttpStatus(404, fn () => $this->snapshots->page($other, $first['id']));
            $this->assertHttpStatus(404, fn () => $this->snapshots->release($other, $first['id']));
        }
        $this->assertHttpStatus(422, fn () => $this->snapshots->page($this->token, $first['id'], 0, 201));
        $this->assertHttpStatus(422, fn () => $this->snapshots->page($this->token, $first['id'], $first['total_items'] + 1));
        $this->snapshots->release($this->token, $first['id']);
        $this->assertDatabaseMissing('reception_sync_snapshot_items', ['snapshot_id' => $first['id']]);
        $new = $this->snapshots->create($this->token, $this->branch);
        $this->travel(25)->hours();
        $this->assertHttpStatus(410, fn () => $this->snapshots->page($this->token, $new['id']));
        $this->assertSame(3, $this->snapshots->pruneExpired());
        $this->assertDatabaseCount('reception_sync_snapshot_items', 0);
    }

    public function test_active_downloads_recover_a_lost_creation_response_and_respect_scope(): void
    {
        $first = $this->snapshots->create($this->token, $this->branch);
        $this->travel(1)->seconds();
        $second = $this->snapshots->create($this->token, $this->branch);
        $active = $this->snapshots->listActive($this->token);
        $this->assertSame([$second, $first], $active);
        $recovered = $this->snapshots->page($this->token, $active[0]['id']);
        $this->assertSame($second['total_items'], count($recovered['items']));

        foreach (['empresa_id', 'sucursal_id', 'device_id'] as $field) {
            $other = clone $this->token;
            $other->{$field} = $field === 'device_id' ? (string) Str::uuid() : 999999;
            $this->assertSame([], $this->snapshots->listActive($other));
        }

        $this->snapshots->release($this->token, $second['id']);
        $this->assertSame([$first], $this->snapshots->listActive($this->token));
        $this->travel(25)->hours();
        $this->assertSame([], $this->snapshots->listActive($this->token));
    }

    private function seedModuleRows(): array
    {
        $typeId = DB::table('tipos_pollo')->insertGetId([
            'codigo' => 'POLLO_VIVO', 'nombre' => 'Pollo vivo', 'permite_despacho' => true,
            'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cageId = DB::table('tipos_java')->insertGetId([
            'codigo' => 'SNAPSHOT', 'nombre' => 'Java histórica', 'peso_kg' => 20,
            'estado' => 'INACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouseId = DB::table('almacenes')->insertGetId([
            'sucursal_id' => $this->branch->id, 'codigo' => 'ALM', 'nombre' => 'Destino histórico',
            'permite_stock_negativo' => false, 'estado' => 'INACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $clientId = DB::table('terceros')->insertGetId([
            'empresa_id' => $this->user->empresa_id, 'tipo_documento' => 'RUC', 'numero_documento' => '20999999999',
            'nombre_razon_social' => 'Cliente anterior', 'direccion' => 'Dirección histórica', 'estado' => 'INACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tercero_roles')->insert(['tercero_id' => $clientId, 'rol' => 'CLIENTE']);
        $journey = JornadaOperativa::query()->create([
            'sucursal_id' => $this->branch->id, 'fecha_operativa' => '2025-01-01', 'estado' => 'CERRADA',
            'abierta_por' => $this->user->id, 'inicio_at' => '2024-12-31 21:00:00', 'cierre_programado_at' => '2025-01-01 21:00:00',
        ]);
        $reception = RecepcionPolloVivo::query()->create(['jornada_id' => $journey->id, 'origen' => 'Camión histórico', 'estado' => 'ABIERTA', 'created_by' => $this->user->id]);
        $line = [
            'numero' => 1, 'sexo' => 'MACHO', 'tipo_pollo_id' => $typeId, 'tipo_java_id' => $cageId,
            'origen_peso' => 'MANUAL', 'aves_por_java' => 7, 'cantidad_javas' => 5, 'cantidad_aves' => 35,
            'peso_java_kg_snapshot' => 7, 'peso_leido_kg' => 135, 'peso_bruto_kg' => 135,
            'tara_total_kg' => 35, 'peso_neto_kg' => 100, 'pesada_at' => '2025-01-01 12:00:00',
            'estado' => 'ACTIVA', 'created_by' => $this->user->id,
        ];
        $weighing = PesadaRecepcionPolloVivo::query()->create([
            ...$line, 'recepcion_id' => $reception->id, 'idempotency_key' => (string) Str::uuid(),
            'columna' => 1, 'propietario_tipo' => 'PROPIA', 'destino_tipo' => 'ALMACEN',
            'almacen_destino_id' => $warehouseId, 'estado' => 'ANULADA', 'motivo_anulacion' => 'Corrección histórica',
        ]);
        $ticket = TicketDespacho::query()->create([
            'jornada_id' => $journey->id, 'codigo' => 'REC-SNAP', 'referencia_externa' => (string) Str::uuid(),
            'canal' => 'MAYORISTA', 'modulo_origen' => TicketDespacho::SOURCE_LIVE_CHICKEN_RECEPTION,
            'tipo_operacion' => 'DESPACHO', 'cliente_destino_id' => $clientId, 'estado' => 'CERRADO',
            'cerrado_por' => $this->user->id, 'cerrado_at' => '2025-01-01 12:30:00', 'created_by' => $this->user->id,
        ]);
        Pesada::query()->create([...$line, 'ticket_id' => $ticket->id]);
        Pesada::query()->create([...$line, 'ticket_id' => $ticket->id, 'numero' => 2, 'estado' => 'ANULADA']);
        RecepcionPolloVivoTicket::query()->create([
            'recepcion_id' => $reception->id, 'ticket_despacho_id' => $ticket->id, 'columna' => 5,
            'request_hash' => str_repeat('a', 64), 'cantidad_javas_aplicada' => 0, 'revision' => 2, 'created_by' => $this->user->id,
        ]);
        $outsider = $ticket->replicate();
        $outsider->codigo = 'Financial-only outsider';
        $outsider->referencia_externa = (string) Str::uuid();
        $outsider->modulo_origen = TicketDespacho::SOURCE_WHOLESALE_TWO;
        $outsider->save();
        Pesada::query()->create([...$line, 'ticket_id' => $outsider->id]);
        // Even an erroneous cross-channel link must not pull general dispatch records.
        RecepcionPolloVivoTicket::query()->create([
            'recepcion_id' => $reception->id, 'ticket_despacho_id' => $outsider->id, 'columna' => 5,
            'request_hash' => str_repeat('b', 64), 'cantidad_javas_aplicada' => 0, 'revision' => 1, 'created_by' => $this->user->id,
        ]);
        $offline = ReceptionSyncRecord::query()->create([
            'company_id' => $this->user->empresa_id, 'branch_id' => $this->branch->id, 'device_id' => (string) Str::uuid(),
            'uuid' => (string) Str::uuid(), 'kind' => 'ticket', 'operating_date' => '2025-02-01', 'status' => 'active',
            'revision' => 1, 'payload' => ['weighings' => []], 'created_by' => $this->user->id,
        ]);

        return ['weighing' => $weighing->id, 'ticket' => $ticket->id, 'offline' => $offline->uuid, 'cage' => $cageId];
    }

    private function allItems(string $id): array
    {
        $items = [];
        $after = 0;
        do {
            $page = $this->snapshots->page($this->token, $id, $after);
            array_push($items, ...$page['items']);
            $after = $page['next_after'];
        } while ($page['has_more']);

        return $items;
    }

    private function assertHttpStatus(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected HTTP status {$status}");
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
