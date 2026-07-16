<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_operation_actor_is_active_for_financial_auditing(): void
    {
        config(['directory.public_access' => true]);
        $user = User::factory()->create();
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = app(OperationContextService::class)->actor(Request::create('/'), $branchId);

        $this->assertTrue($actor->isActive());
        $this->assertSame($user->empresa_id, $actor->empresa_id);
        $this->assertSame($branchId, $actor->sucursal_id);
        $this->assertSame('sistema-operacion@local.invalid', $actor->email);
    }

    public function test_existing_inactive_public_operation_actor_is_reactivated(): void
    {
        config(['directory.public_access' => true]);
        $user = User::factory()->create();
        $branchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'PRINCIPAL',
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $systemActor = User::query()->create([
            'empresa_id' => $user->empresa_id,
            'sucursal_id' => $branchId,
            'nombre' => 'Sistema operacion local',
            'email' => 'sistema-operacion@local.invalid',
            'password_hash' => Hash::make('not-a-login-password'),
            'estado' => User::STATUS_INACTIVE,
        ]);

        $actor = app(OperationContextService::class)->actor(Request::create('/'), $branchId);

        $this->assertSame($systemActor->id, $actor->id);
        $this->assertTrue($actor->isActive());
        $this->assertDatabaseHas('usuarios', [
            'id' => $systemActor->id,
            'estado' => User::STATUS_ACTIVE,
        ]);
    }
}
