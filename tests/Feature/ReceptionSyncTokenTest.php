<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateReceptionSyncToken;
use App\Models\ReceptionSyncToken;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\ModuleAvailabilityService;
use App\Services\ReceptionSyncTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ReceptionSyncTokenTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private Sucursal $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['debe_cambiar_password' => false]);
        $this->branch = $this->createBranch($this->user);
        $this->user->update(['sucursal_id' => $this->branch->id]);
        $this->makeAdministrator($this->user);

        Route::get('/api/reception-sync-auth-probe', function (Request $request) {
            return response()->json([
                'user_id' => $request->user()->id,
                'empresa_id' => $request->user()->empresa_id,
                'sucursal_id' => $request->user()->sucursal_id,
                'device_id' => $request->attributes->get('reception_sync_token')->device_id,
                'branch_id' => $request->attributes->get('reception_sync_branch')->id,
            ]);
        })->middleware(AuthenticateReceptionSyncToken::class);
    }

    public function test_token_uses_only_hash_storage_and_is_bound_to_its_company_branch_and_device(): void
    {
        $issued = $this->issue();
        $plain = $issued['plain_text_token'];
        $token = $issued['token'];

        $this->assertMatchesRegularExpression('/^rpv_[a-f0-9]{64}$/', $plain);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertStringNotContainsString($plain, $token->toJson());
        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $this->assertArrayNotHasKey('password_fingerprint', $token->toArray());

        $this->withToken($plain)->getJson('/api/reception-sync-auth-probe?empresa_id=999&sucursal_id=999')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('empresa_id', $this->user->empresa_id)
            ->assertJsonPath('sucursal_id', $this->branch->id)
            ->assertJsonPath('branch_id', $this->branch->id)
            ->assertJsonPath('device_id', $token->device_id);
        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function test_a_company_administrator_without_branch_is_scoped_to_the_token_branch(): void
    {
        $this->user->update(['sucursal_id' => null]);
        $issued = $this->issue();
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')
            ->assertOk()->assertJsonPath('sucursal_id', $this->branch->id);
        $this->assertNull($this->user->fresh()->sucursal_id);
    }

    public function test_web_session_or_a_normal_sanctum_token_cannot_authorize_sync(): void
    {
        $this->actingAs($this->user)->getJson('/api/reception-sync-auth-probe')->assertUnauthorized();
        $normalToken = $this->user->createToken('Normal API', ['api'])->plainTextToken;
        $this->withToken($normalToken)->getJson('/api/reception-sync-auth-probe')->assertUnauthorized();
    }

    public function test_sync_token_cannot_access_existing_reception_or_other_module_apis(): void
    {
        $issued = $this->issue();
        $this->withToken($issued['plain_text_token'])->getJson('/api/v1/recepcion-pollo-vivo')->assertUnauthorized();
        $this->withToken($issued['plain_text_token'])->getJson('/api/v1/finanzas/saldos')->assertUnauthorized();
    }

    public function test_query_string_tokens_are_not_accepted(): void
    {
        $issued = $this->issue();
        $this->getJson('/api/reception-sync-auth-probe?token='.$issued['plain_text_token'])->assertUnauthorized();
    }

    public function test_revoked_and_expired_tokens_are_rejected(): void
    {
        $expired = $this->issue();
        $expired['token']->update(['expires_at' => now()->subSecond()]);
        $this->withToken($expired['plain_text_token'])->getJson('/api/reception-sync-auth-probe')
            ->assertUnauthorized()->assertJsonPath('code', 'SYNC_UNAUTHENTICATED');

        $revoked = $this->issue();
        app(ReceptionSyncTokenService::class)->revoke($revoked['token']);
        $this->withToken($revoked['plain_text_token'])->getJson('/api/reception-sync-auth-probe')
            ->assertUnauthorized();
    }

    public function test_changing_password_or_requiring_password_change_invalidates_device_access(): void
    {
        $issued = $this->issue();
        $this->user->update(['debe_cambiar_password' => true]);
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();
        $this->user->update(['debe_cambiar_password' => false, 'password_hash' => 'new-device-password']);
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();
    }

    public function test_inactive_user_company_or_branch_invalidates_device_access(): void
    {
        $issued = $this->issue();
        $this->user->update(['estado' => 'INACTIVO']);
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();

        $this->user->update(['estado' => 'ACTIVO']);
        $this->user->empresa->update(['estado' => 'INACTIVO']);
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();

        $this->user->empresa->update(['estado' => 'ACTIVO']);
        $this->branch->update(['estado' => 'INACTIVO']);
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();
    }

    public function test_disabling_module_or_removing_user_permission_invalidates_device_access(): void
    {
        $issued = $this->issue();
        $availability = app(ModuleAvailabilityService::class);
        $availability->setEnabled(ReceptionSyncTokenService::MODULE, false);
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();
        $availability->setEnabled(ReceptionSyncTokenService::MODULE, true);
        $this->user->roles()->detach();
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();
    }

    public function test_moving_user_to_another_branch_or_company_invalidates_existing_tokens(): void
    {
        $issued = $this->issue();
        $otherBranch = $this->createBranch($this->user);
        $this->user->update(['sucursal_id' => $otherBranch->id]);
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();

        $otherUser = User::factory()->create();
        $this->user->update(['sucursal_id' => $this->branch->id, 'empresa_id' => $otherUser->empresa_id]);
        $this->withToken($issued['plain_text_token'])->getJson('/api/reception-sync-auth-probe')->assertForbidden();
    }

    public function test_management_shows_plaintext_only_in_creation_response_and_can_revoke(): void
    {
        $response = $this->actingAs($this->user)->post('/recepcion-pollo-vivo/dispositivos', $this->form());
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $plain = $response->viewData('plainTextToken');
        $this->assertMatchesRegularExpression('/^rpv_[a-f0-9]{64}$/', $plain);
        $response->assertSee($plain);
        $token = ReceptionSyncToken::query()->sole();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertStringNotContainsString($plain, json_encode(session()->all()));
        $this->get('/recepcion-pollo-vivo/dispositivos')->assertOk()->assertDontSee($plain);

        $this->delete('/recepcion-pollo-vivo/dispositivos/'.$token->id)->assertRedirect();
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->withToken($plain)->getJson('/api/reception-sync-auth-probe')->assertUnauthorized();
    }

    public function test_management_is_admin_only_and_rejects_out_of_scope_branches_and_tokens(): void
    {
        $otherUser = User::factory()->create(['debe_cambiar_password' => false]);
        $this->makeAdministrator($otherUser);
        $otherBranch = $this->createBranch($otherUser);
        $foreign = app(ReceptionSyncTokenService::class)->issue($otherUser, $otherBranch, 'Dispositivo empresa ajena');
        $sameCompanyBranch = $this->createBranch($this->user);

        $this->actingAs($this->user)->get('/recepcion-pollo-vivo/dispositivos')
            ->assertOk()->assertDontSee('Dispositivo empresa ajena');
        $this->post('/recepcion-pollo-vivo/dispositivos', $this->form(['sucursal_id' => $otherBranch->id]))->assertNotFound();
        $this->post('/recepcion-pollo-vivo/dispositivos', $this->form(['sucursal_id' => $sameCompanyBranch->id]))->assertNotFound();
        $this->delete('/recepcion-pollo-vivo/dispositivos/'.$foreign['token']->id)->assertNotFound();
        $this->assertNull($foreign['token']->fresh()->revoked_at);

        $this->user->roles()->detach();
        $this->grantModules($this->user, [ReceptionSyncTokenService::MODULE]);
        $this->get('/recepcion-pollo-vivo/dispositivos')->assertForbidden();
        $this->post('/recepcion-pollo-vivo/dispositivos', $this->form())->assertForbidden();
    }

    public function test_generation_rejects_invalid_lifetime_device_id_and_inactive_branch(): void
    {
        $this->actingAs($this->user)
            ->post('/recepcion-pollo-vivo/dispositivos', $this->form(['expires_in_days' => 366]))
            ->assertSessionHasErrors('expires_in_days');
        $this->post('/recepcion-pollo-vivo/dispositivos', $this->form(['device_id' => 'invalid']))
            ->assertSessionHasErrors('device_id');
        $this->branch->update(['estado' => 'INACTIVO']);
        $this->post('/recepcion-pollo-vivo/dispositivos', $this->form())->assertNotFound();
        $this->assertDatabaseCount('reception_sync_tokens', 0);
    }

    public function test_administrator_can_download_documentation_without_exposing_device_secrets(): void
    {
        $issued = $this->issue();
        $this->actingAs($this->user);

        foreach ([
            'documentacion' => 'recepcion-pollo-vivo-sync.md',
            'openapi' => 'openapi-recepcion-pollo-vivo.json',
        ] as $endpoint => $filename) {
            $response = $this->get('/recepcion-pollo-vivo/dispositivos/'.$endpoint);
            $response->assertOk()->assertDownload($filename)->assertHeader('Cache-Control', 'no-store, private');
            $downloadedPath = $response->baseResponse->getFile()->getPathname();
            $this->assertSame(base_path('docs/'.$filename), $downloadedPath);
            $this->assertStringNotContainsString($issued['plain_text_token'], file_get_contents($downloadedPath));
        }

        $this->get('/recepcion-pollo-vivo/dispositivos')
            ->assertOk()->assertSee('Descargar guía')->assertSee('Descargar OpenAPI');
    }

    public function test_documentation_downloads_require_an_administrator_session(): void
    {
        $this->get('/recepcion-pollo-vivo/dispositivos/documentacion')->assertRedirect(route('login'));
        $this->get('/recepcion-pollo-vivo/dispositivos/openapi')->assertRedirect(route('login'));

        $this->user->roles()->detach();
        $this->grantModules($this->user, [ReceptionSyncTokenService::MODULE]);
        $this->actingAs($this->user);
        $this->get('/recepcion-pollo-vivo/dispositivos/documentacion')->assertForbidden();
        $this->get('/recepcion-pollo-vivo/dispositivos/openapi')->assertForbidden();
    }

    private function createBranch(User $user): Sucursal
    {
        return Sucursal::query()->create([
            'empresa_id' => $user->empresa_id,
            'codigo' => 'SYNC-'.Str::random(8),
            'nombre' => 'Sucursal '.Str::random(8),
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
        ]);
    }

    private function issue(): array
    {
        return app(ReceptionSyncTokenService::class)->issue($this->user, $this->branch, 'Recepción principal');
    }

    private function form(array $overrides = []): array
    {
        return [
            'sucursal_id' => $this->branch->id,
            'device_name' => 'Recepción principal',
            'expires_in_days' => 90,
            ...$overrides,
        ];
    }
}
