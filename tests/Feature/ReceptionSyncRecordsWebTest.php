<?php

namespace Tests\Feature;

use App\Models\ReceptionSyncRecord;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ReceptionSyncRecordsWebTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_web_query_is_scoped_and_excludes_voided_records_from_active_totals(): void
    {
        $user = User::factory()->create(['debe_cambiar_password' => false]);
        $branch = $this->branch($user);
        $user->update(['sucursal_id' => $branch->id]);
        $this->makeAdministrator($user);
        $this->record($user, $branch, 'VISIBLE-001');
        $this->record($user, $branch, 'VOIDED-001', 'voided');
        $this->record($user, $this->branch($user), 'OTHER-BRANCH');
        $otherUser = User::factory()->create();
        $this->record($otherUser, $this->branch($otherUser), 'OTHER-COMPANY');

        $this->actingAs($user)->get('/recepcion-pollo-vivo/sincronizados?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertSee('VISIBLE-001')->assertSee('VOIDED-001')
            ->assertDontSee('OTHER-BRANCH')->assertDontSee('OTHER-COMPANY')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['active_records'] === 1
                && $summary['voided_records'] === 1 && $summary['net_weight_kg'] === 125.125
                && $summary['birds'] === 40.0);

        $this->assertDatabaseCount('reception_sync_records', 4);
    }

    public function test_web_query_requires_login_and_valid_date_range(): void
    {
        $this->get('/recepcion-pollo-vivo/sincronizados')->assertRedirect('/login');
        $user = User::factory()->create(['debe_cambiar_password' => false]);
        $branch = $this->branch($user);
        $user->update(['sucursal_id' => $branch->id]);
        $this->makeAdministrator($user);

        $this->actingAs($user)->get('/recepcion-pollo-vivo/sincronizados?date_from=2026-09-20&date_to=2026-09-01')
            ->assertSessionHasErrors('date_to');
    }

    private function branch(User $user): Sucursal
    {
        return Sucursal::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => Str::random(10),
            'nombre' => 'Sucursal de pruebas',
            'zona_horaria' => 'America/Bogota',
            'estado' => 'ACTIVO',
        ]);
    }

    private function record(User $user, Sucursal $branch, string $number, string $status = 'active'): void
    {
        ReceptionSyncRecord::query()->create([
            'company_id' => $user->empresa_id, 'branch_id' => $branch->id,
            'device_id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(),
            'kind' => 'reception', 'operating_date' => '2026-09-09', 'status' => $status,
            'revision' => 1, 'created_by' => $user->id,
            'payload' => [
                'local_number' => $number, 'lane' => 1,
                'destination' => ['name' => 'Almacén principal'],
                'owner' => ['name' => 'Mi empresa'], 'weighings' => [],
                'totals' => ['birds' => 40, 'cages' => 5, 'net_weight_kg' => 125.125],
            ],
        ]);
    }
}
