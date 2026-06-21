<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_and_use_bearer_token(): void
    {
        User::factory()->create([
            'email' => 'operador@example.com',
            'password_hash' => 'clave-segura',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'operador@example.com',
            'password' => 'clave-segura',
            'device_name' => 'prueba',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'operador@example.com');

        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'operador@example.com');
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'operador@example.com',
            'password_hash' => 'clave-correcta',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'operador@example.com',
            'password' => 'clave-incorrecta',
        ])->assertUnauthorized();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'inactivo@example.com',
            'password_hash' => 'clave-segura',
            'estado' => User::STATUS_INACTIVE,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactivo@example.com',
            'password' => 'clave-segura',
        ])->assertForbidden();
    }

    public function test_existing_token_is_rejected_after_user_is_deactivated(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('prueba', ['api'])->plainTextToken;

        $user->update(['estado' => User::STATUS_INACTIVE]);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }

    public function test_permission_middleware_uses_current_database_permissions(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'permission:PRECIOS_GESTIONAR'])
            ->get('/api/test/precios', fn () => response()->json(['ok' => true]));

        $permission = Permission::query()->create([
            'codigo' => 'PRECIOS_GESTIONAR',
            'descripcion' => 'Gestionar precios',
        ]);
        $user = User::factory()->create();
        $role = Role::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'ADMINISTRADOR',
            'nombre' => 'Administrador',
        ]);
        $role->permissions()->attach($permission);

        $user->roles()->attach($role);
        $token = $user->createToken('prueba', ['api'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/test/precios')
            ->assertOk();

        $role->permissions()->detach($permission);

        $this->withToken($token)
            ->getJson('/api/test/precios')
            ->assertForbidden();
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('prueba', ['api'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
