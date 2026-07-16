<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class AccessAdministrationApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_module_catalog_and_administration_endpoints_require_access_module(): void
    {
        $unauthorized = User::factory()->create();
        $this->grantModules($unauthorized, ['MODULO_FINANZAS']);
        Sanctum::actingAs($unauthorized, ['api']);

        $this->getJson('/api/v1/admin/modules')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/roles')->assertForbidden();

        $authorized = User::factory()->create();
        $this->grantModules($authorized, ['MODULO_USUARIOS_ROLES']);
        Sanctum::actingAs($authorized, ['api']);

        $response = $this->getJson('/api/v1/admin/modules')->assertOk();

        foreach ([
            'MODULO_DESPACHO_MAYORISTA',
            'MODULO_DESPACHO_MINORISTA_1',
            'MODULO_DESPACHO_MINORISTA_2',
            'MODULO_RESUMEN_JORNADA',
            'MODULO_GESTION_PESADAS',
            'MODULO_DIRECTORIO',
            'MODULO_FLOTA',
            'MODULO_FINANZAS',
            'MODULO_CONTROL_JAVAS',
            'MODULO_JORNADA_PROVEEDORES',
            'MODULO_USUARIOS_ROLES',
        ] as $moduleCode) {
            $response->assertJsonFragment(['code' => $moduleCode]);
        }

        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/roles')->assertOk();
    }

    public function test_authorized_manager_can_create_role_using_only_module_codes(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);
        Sanctum::actingAs($manager, ['api']);

        $response = $this->postJson('/api/v1/admin/roles', [
            'code' => 'TESORERIA',
            'name' => 'Tesorería',
            'module_codes' => [
                'MODULO_FINANZAS',
                'MODULO_DIRECTORIO',
            ],
        ])->assertCreated();

        $role = Role::query()->findOrFail($response->json('data.id'));

        $this->assertSame($manager->empresa_id, $role->empresa_id);
        $this->assertEqualsCanonicalizing(
            ['MODULO_FINANZAS', 'MODULO_DIRECTORIO'],
            $role->permissions()->pluck('codigo')->all(),
        );
    }

    public function test_role_api_rejects_internal_operation_permissions_as_module_codes(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);
        Sanctum::actingAs($manager, ['api']);

        $this->postJson('/api/v1/admin/roles', [
            'code' => 'PERMISOS_TECNICOS',
            'name' => 'Permisos técnicos',
            'module_codes' => ['FINANZAS_VER', 'PAGOS_ANULAR'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_codes');

        $this->assertDatabaseMissing('roles', [
            'empresa_id' => $manager->empresa_id,
            'codigo' => 'PERMISOS_TECNICOS',
        ]);
    }

    public function test_authorized_manager_can_create_user_with_roles_and_hashed_password(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);
        $operatorRole = Role::query()->create([
            'empresa_id' => $manager->empresa_id,
            'codigo' => 'OPERADOR',
            'nombre' => 'Operador',
        ]);
        Sanctum::actingAs($manager, ['api']);

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Operador Nuevo',
            'email' => 'operador.nuevo@example.com',
            'branch_id' => null,
            'role_ids' => [$operatorRole->id],
            'status' => User::STATUS_ACTIVE,
            'password' => 'Clave-temporal-123',
            'password_confirmation' => 'Clave-temporal-123',
            'must_change_password' => true,
        ])->assertCreated();

        $created = User::query()->findOrFail($response->json('data.id'));

        $this->assertSame($manager->empresa_id, $created->empresa_id);
        $this->assertSame('Operador Nuevo', $created->nombre);
        $this->assertTrue($created->debe_cambiar_password);
        $this->assertTrue(Hash::check('Clave-temporal-123', $created->password_hash));
        $this->assertEquals([$operatorRole->id], $created->roles()->pluck('roles.id')->all());
        $this->assertDatabaseHas('auditoria_eventos', [
            'empresa_id' => $manager->empresa_id,
            'usuario_id' => $manager->id,
            'entidad' => 'usuario',
            'entidad_id' => (string) $created->id,
            'accion' => 'CREAR',
        ]);

        $auditPayload = (string) DB::table('auditoria_eventos')
            ->where('entidad', 'usuario')
            ->where('entidad_id', (string) $created->id)
            ->value('datos_despues');
        $this->assertStringNotContainsString('Clave-temporal-123', $auditPayload);
        $this->assertStringNotContainsString('password_hash', $auditPayload);
    }

    public function test_user_and_role_lists_are_scoped_to_the_authenticated_company(): void
    {
        $manager = User::factory()->create(['email' => 'manager@empresa-a.test']);
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);
        $ownUser = $this->createUserForCompany($manager, [
            'email' => 'visible@empresa-a.test',
        ]);
        $ownRole = Role::query()->create([
            'empresa_id' => $manager->empresa_id,
            'codigo' => 'VISIBLE',
            'nombre' => 'Visible',
        ]);

        $otherUser = User::factory()->create([
            'email' => 'hidden@empresa-b.test',
        ]);
        $otherRole = Role::query()->create([
            'empresa_id' => $otherUser->empresa_id,
            'codigo' => 'OCULTO',
            'nombre' => 'Oculto',
        ]);

        Sanctum::actingAs($manager, ['api']);

        $this->getJson('/api/v1/admin/users?search=empresa')
            ->assertOk()
            ->assertJsonFragment(['email' => $ownUser->email])
            ->assertJsonMissing(['email' => $otherUser->email]);

        $this->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonFragment(['code' => $ownRole->codigo])
            ->assertJsonMissing(['code' => $otherRole->codigo]);
    }

    public function test_user_list_filters_by_branch_and_rejects_foreign_branch_filter(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);
        $northBranchId = $this->insertBranch($manager, 'NORTE', 'Sucursal norte');
        $southBranchId = $this->insertBranch($manager, 'SUR', 'Sucursal sur');
        $northUser = $this->createUserForCompany($manager, [
            'email' => 'norte@example.com',
            'sucursal_id' => $northBranchId,
        ]);
        $southUser = $this->createUserForCompany($manager, [
            'email' => 'sur@example.com',
            'sucursal_id' => $southBranchId,
        ]);

        $otherCompanyUser = User::factory()->create();
        $foreignBranchId = $this->insertBranch(
            $otherCompanyUser,
            'AJENA',
            'Sucursal ajena',
        );

        Sanctum::actingAs($manager, ['api']);

        $this->getJson("/api/v1/admin/users?branch_id={$northBranchId}")
            ->assertOk()
            ->assertJsonFragment(['email' => $northUser->email])
            ->assertJsonMissing(['email' => $southUser->email]);

        $this->getJson("/api/v1/admin/users?branch_id={$foreignBranchId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_records_from_another_company_cannot_be_read_updated_or_deleted(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);

        $otherUser = User::factory()->create();
        $otherRole = Role::query()->create([
            'empresa_id' => $otherUser->empresa_id,
            'codigo' => 'OTRA_EMPRESA',
            'nombre' => 'Otra empresa',
        ]);

        Sanctum::actingAs($manager, ['api']);

        $this->getJson("/api/v1/admin/users/{$otherUser->id}")->assertNotFound();
        $this->putJson("/api/v1/admin/users/{$otherUser->id}", [
            'name' => 'Intento de cambio',
        ])->assertNotFound();
        $this->patchJson("/api/v1/admin/users/{$otherUser->id}/status", [
            'status' => User::STATUS_INACTIVE,
        ])->assertNotFound();
        $this->postJson("/api/v1/admin/users/{$otherUser->id}/reset-password", [
            'password' => 'Nueva-clave-123',
            'password_confirmation' => 'Nueva-clave-123',
        ])->assertNotFound();

        $this->getJson("/api/v1/admin/roles/{$otherRole->id}")->assertNotFound();
        $this->putJson("/api/v1/admin/roles/{$otherRole->id}", [
            'name' => 'Intento de cambio',
        ])->assertNotFound();
        $this->deleteJson("/api/v1/admin/roles/{$otherRole->id}")->assertNotFound();

        $this->assertDatabaseHas('usuarios', [
            'id' => $otherUser->id,
            'nombre' => $otherUser->nombre,
            'estado' => User::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('roles', [
            'id' => $otherRole->id,
            'nombre' => 'Otra empresa',
        ]);
    }

    public function test_role_from_another_company_cannot_be_assigned_on_create_or_update(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);
        $ownRole = Role::query()->create([
            'empresa_id' => $manager->empresa_id,
            'codigo' => 'PROPIO',
            'nombre' => 'Propio',
        ]);
        $existingUser = $this->createUserForCompany($manager);
        $existingUser->roles()->attach($ownRole);

        $otherCompanyUser = User::factory()->create();
        $foreignRole = Role::query()->create([
            'empresa_id' => $otherCompanyUser->empresa_id,
            'codigo' => 'AJENO',
            'nombre' => 'Ajeno',
        ]);

        Sanctum::actingAs($manager, ['api']);

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Usuario Inválido',
            'email' => 'usuario.invalido@example.com',
            'role_ids' => [$foreignRole->id],
            'status' => User::STATUS_ACTIVE,
            'password' => 'Clave-temporal-123',
            'password_confirmation' => 'Clave-temporal-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_ids.0');

        $this->putJson("/api/v1/admin/users/{$existingUser->id}", [
            'role_ids' => [$foreignRole->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_ids.0');

        $this->assertDatabaseMissing('usuarios', [
            'empresa_id' => $manager->empresa_id,
            'email' => 'usuario.invalido@example.com',
        ]);
        $this->assertEquals([$ownRole->id], $existingUser->roles()->pluck('roles.id')->all());
    }

    public function test_branch_from_another_company_cannot_be_assigned_to_managed_or_own_account(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);
        $ownRole = Role::query()->create([
            'empresa_id' => $manager->empresa_id,
            'codigo' => 'OPERADOR',
            'nombre' => 'Operador',
        ]);
        $managedUser = $this->createUserForCompany($manager);
        $otherCompanyUser = User::factory()->create();
        $foreignBranchId = DB::table('sucursales')->insertGetId([
            'empresa_id' => $otherCompanyUser->empresa_id,
            'codigo' => 'AJENA',
            'nombre' => 'Sucursal ajena',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($manager, ['api']);

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Sucursal Inválida',
            'email' => 'sucursal.invalida@example.com',
            'branch_id' => $foreignBranchId,
            'role_ids' => [$ownRole->id],
            'password' => 'Clave-temporal-123',
            'password_confirmation' => 'Clave-temporal-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->putJson("/api/v1/admin/users/{$managedUser->id}", [
            'branch_id' => $foreignBranchId,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->putJson('/api/v1/account', [
            'branch_id' => $foreignBranchId,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->assertNull($managedUser->fresh()->sucursal_id);
        $this->assertNull($manager->fresh()->sucursal_id);
    }

    public function test_administrator_cannot_deactivate_their_own_account(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);
        Sanctum::actingAs($administrator, ['api']);

        $this->patchJson("/api/v1/admin/users/{$administrator->id}/status", [
            'status' => User::STATUS_INACTIVE,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(User::STATUS_ACTIVE, $administrator->fresh()->estado);
    }

    public function test_company_cannot_be_left_without_an_active_administrator(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);

        $lastAdministrator = $this->createUserForCompany($manager);
        $adminRole = $this->makeAdministrator($lastAdministrator);
        $ordinaryRole = Role::query()->create([
            'empresa_id' => $manager->empresa_id,
            'codigo' => 'OPERADOR',
            'nombre' => 'Operador',
        ]);

        Sanctum::actingAs($manager, ['api']);

        $this->patchJson("/api/v1/admin/users/{$lastAdministrator->id}/status", [
            'status' => User::STATUS_INACTIVE,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->putJson("/api/v1/admin/users/{$lastAdministrator->id}", [
            'role_ids' => [$ordinaryRole->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_ids');

        $this->assertSame(User::STATUS_ACTIVE, $lastAdministrator->fresh()->estado);
        $this->assertTrue($lastAdministrator->roles()->whereKey($adminRole->id)->exists());
    }

    public function test_deactivation_is_allowed_when_another_active_administrator_remains(): void
    {
        $administrator = User::factory()->create();
        $adminRole = $this->makeAdministrator($administrator);
        $otherAdministrator = $this->createUserForCompany($administrator);
        $otherAdministrator->roles()->attach($adminRole);
        $token = $otherAdministrator->createToken('terminal')->accessToken;
        $this->insertSession($otherAdministrator, 'other-admin-session');

        Sanctum::actingAs($administrator, ['api']);

        $this->patchJson("/api/v1/admin/users/{$otherAdministrator->id}/status", [
            'status' => User::STATUS_INACTIVE,
        ])->assertOk();

        $this->assertSame(User::STATUS_INACTIVE, $otherAdministrator->fresh()->estado);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-admin-session']);
    }

    public function test_changing_roles_revokes_only_the_affected_users_sessions(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);
        $target = $this->createUserForCompany($administrator);
        $firstRole = Role::query()->create([
            'empresa_id' => $administrator->empresa_id,
            'codigo' => 'PRIMERO',
            'nombre' => 'Primero',
        ]);
        $secondRole = Role::query()->create([
            'empresa_id' => $administrator->empresa_id,
            'codigo' => 'SEGUNDO',
            'nombre' => 'Segundo',
        ]);
        $target->roles()->attach($firstRole);

        $targetToken = $target->createToken('target')->accessToken;
        $adminToken = $administrator->createToken('admin')->accessToken;
        $this->insertSession($target, 'target-session');
        $this->insertSession($administrator, 'admin-session');

        Sanctum::actingAs($administrator, ['api']);

        $this->putJson("/api/v1/admin/users/{$target->id}", [
            'role_ids' => [$secondRole->id],
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $targetToken->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $adminToken->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-session']);
        $this->assertEquals([$secondRole->id], $target->roles()->pluck('roles.id')->all());
    }

    public function test_changing_modules_on_a_role_revokes_sessions_of_users_with_that_role(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);
        $affectedUser = $this->createUserForCompany($administrator);
        $role = $this->grantModules(
            $affectedUser,
            ['MODULO_DIRECTORIO'],
            'ROL_CAMBIANTE',
            'Rol cambiante',
        );
        $unaffectedUser = $this->createUserForCompany($administrator);

        $affectedToken = $affectedUser->createToken('affected')->accessToken;
        $unaffectedToken = $unaffectedUser->createToken('unaffected')->accessToken;
        $this->insertSession($affectedUser, 'affected-session');
        $this->insertSession($unaffectedUser, 'unaffected-session');

        Sanctum::actingAs($administrator, ['api']);

        $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'module_codes' => ['MODULO_FINANZAS'],
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $affectedToken->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'affected-session']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $unaffectedToken->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'unaffected-session']);
        $this->assertSame(
            ['MODULO_FINANZAS'],
            $role->fresh()->permissions()->pluck('codigo')->all(),
        );
    }

    public function test_administrator_role_and_roles_in_use_cannot_be_deleted(): void
    {
        $administrator = User::factory()->create();
        $adminRole = $this->makeAdministrator($administrator);
        $inUseRole = $this->grantModules(
            $this->createUserForCompany($administrator),
            ['MODULO_DIRECTORIO'],
            'EN_USO',
            'En uso',
        );

        Sanctum::actingAs($administrator, ['api']);

        $this->deleteJson("/api/v1/admin/roles/{$adminRole->id}")
            ->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/roles/{$inUseRole->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('roles', ['id' => $adminRole->id]);
        $this->assertDatabaseHas('roles', ['id' => $inUseRole->id]);
    }

    private function insertSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);
    }

    private function insertBranch(User $companyUser, string $code, string $name): int
    {
        return DB::table('sucursales')->insertGetId([
            'empresa_id' => $companyUser->empresa_id,
            'codigo' => $code,
            'nombre' => $name,
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
