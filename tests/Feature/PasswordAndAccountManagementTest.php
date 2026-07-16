<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class PasswordAndAccountManagementTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_active_user_can_log_in_and_log_out_with_web_session(): void
    {
        $user = User::factory()->create([
            'email' => 'sesion.web@example.com',
            'password_hash' => 'Clave-web-123',
        ]);

        $this->post('/login', [
            'login' => 'sesion.web@example.com',
            'password' => 'Clave-web-123',
        ])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);

        $this->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_create_a_web_session(): void
    {
        User::factory()->create([
            'email' => 'inactivo.web@example.com',
            'password_hash' => 'Clave-web-123',
            'estado' => User::STATUS_INACTIVE,
        ]);

        $this->from('/login')->post('/login', [
            'login' => 'inactivo.web@example.com',
            'password' => 'Clave-web-123',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_user_with_temporary_password_is_forced_to_account_until_it_is_changed(): void
    {
        $user = User::factory()->create([
            'email' => 'temporal@example.com',
            'password_hash' => 'Clave-temporal-123',
            'debe_cambiar_password' => true,
        ]);
        $this->grantModules($user, ['MODULO_FINANZAS']);

        $this->post('/login', [
            'login' => 'temporal@example.com',
            'password' => 'Clave-temporal-123',
        ])->assertRedirect('/mi-cuenta');

        $this->get('/mi-cuenta')
            ->assertOk()
            ->assertSee('Mi cuenta');
        $this->get('/')->assertRedirect('/mi-cuenta');
        $this->get('/finanzas')->assertRedirect('/mi-cuenta');

        Sanctum::actingAs($user, ['api']);
        $this->getJson('/api/v1/finanzas/catalogo')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_account_endpoint_only_updates_safe_profile_fields(): void
    {
        $user = User::factory()->create([
            'nombre' => 'Nombre Original',
            'email' => 'original@example.com',
            'debe_cambiar_password' => false,
        ]);
        $originalCompanyId = $user->empresa_id;
        Sanctum::actingAs($user, ['api']);

        $this->putJson('/api/v1/account', [
            'name' => 'Nombre Actualizado',
            'email' => 'actualizado@example.com',
            'branch_id' => null,
            'status' => User::STATUS_INACTIVE,
            'empresa_id' => 999999,
            'must_change_password' => true,
            'role_ids' => [999999],
        ])->assertOk();

        $user->refresh();

        $this->assertSame('Nombre Actualizado', $user->nombre);
        $this->assertSame('actualizado@example.com', $user->email);
        $this->assertSame(User::STATUS_ACTIVE, $user->estado);
        $this->assertSame($originalCompanyId, $user->empresa_id);
        $this->assertFalse($user->debe_cambiar_password);
        $this->assertCount(0, $user->roles);
    }

    public function test_account_resource_never_exposes_password_hash(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['api']);

        $this->getJson('/api/v1/account')
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.password_hash');
    }

    public function test_password_change_rejects_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'password_hash' => 'Clave-actual-123',
        ]);
        $token = $user->createToken('terminal')->accessToken;
        Sanctum::actingAs($user, ['api']);

        $this->putJson('/api/v1/account/password', [
            'current_password' => 'Clave-incorrecta-123',
            'password' => 'Clave-nueva-123',
            'password_confirmation' => 'Clave-nueva-123',
            'revoke_other_sessions' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Clave-actual-123', $user->fresh()->password_hash));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_password_change_clears_requirement_and_revokes_other_sessions(): void
    {
        $user = User::factory()->create([
            'email' => 'cambio.password@example.com',
            'password_hash' => 'Clave-actual-123',
            'debe_cambiar_password' => true,
        ]);
        $currentToken = $user->createToken('current-device');
        $otherToken = $user->createToken('other-device')->accessToken;
        $this->insertSession($user, 'web-session');

        $this->withToken($currentToken->plainTextToken)
            ->putJson('/api/v1/account/password', [
                'current_password' => 'Clave-actual-123',
                'password' => 'Clave-nueva-456',
                'password_confirmation' => 'Clave-nueva-456',
                'revoke_other_sessions' => true,
            ])
            ->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('Clave-nueva-456', $user->password_hash));
        $this->assertFalse($user->debe_cambiar_password);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'web-session']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'cambio.password@example.com',
            'password' => 'Clave-actual-123',
        ])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'cambio.password@example.com',
            'password' => 'Clave-nueva-456',
        ])->assertOk();
    }

    public function test_user_can_keep_other_sessions_when_changing_password_if_requested(): void
    {
        $user = User::factory()->create([
            'password_hash' => 'Clave-actual-123',
        ]);
        $currentToken = $user->createToken('current-device');
        $otherToken = $user->createToken('other-device')->accessToken;
        $this->insertSession($user, 'web-session');

        $this->withToken($currentToken->plainTextToken)
            ->putJson('/api/v1/account/password', [
                'current_password' => 'Clave-actual-123',
                'password' => 'Clave-nueva-456',
                'password_confirmation' => 'Clave-nueva-456',
                'revoke_other_sessions' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'web-session']);
    }

    public function test_administrative_password_reset_is_temporary_and_revokes_target_sessions_only(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);
        $target = $this->createUserForCompany($administrator, [
            'password_hash' => 'Clave-anterior-123',
            'debe_cambiar_password' => false,
        ]);

        $adminToken = $administrator->createToken('admin')->accessToken;
        $targetToken = $target->createToken('target')->accessToken;
        $this->insertSession($administrator, 'admin-session');
        $this->insertSession($target, 'target-session');

        Sanctum::actingAs($administrator, ['api']);

        $this->postJson("/api/v1/admin/users/{$target->id}/reset-password", [
            'password' => 'Temporal-nueva-456',
            'password_confirmation' => 'Temporal-nueva-456',
        ])->assertOk();

        $target->refresh();
        $this->assertTrue(Hash::check('Temporal-nueva-456', $target->password_hash));
        $this->assertTrue($target->debe_cambiar_password);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $targetToken->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $adminToken->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-session']);

        $audit = DB::table('auditoria_eventos')
            ->where('empresa_id', $administrator->empresa_id)
            ->where('usuario_id', $administrator->id)
            ->where('entidad', 'usuario')
            ->where('entidad_id', (string) $target->id)
            ->where('accion', 'RESTABLECER_PASSWORD')
            ->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString(
            'Temporal-nueva-456',
            (string) $audit->datos_despues,
        );
    }

    public function test_administrator_can_explicitly_revoke_a_users_sessions_without_changing_data(): void
    {
        $administrator = User::factory()->create();
        $this->makeAdministrator($administrator);
        $target = $this->createUserForCompany($administrator);
        $originalPasswordHash = $target->password_hash;
        $targetToken = $target->createToken('target')->accessToken;
        $this->insertSession($target, 'target-session');

        Sanctum::actingAs($administrator, ['api']);

        $this->postJson("/api/v1/admin/users/{$target->id}/revoke-sessions")
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $targetToken->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertSame($originalPasswordHash, $target->fresh()->password_hash);
        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->estado);
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
}
