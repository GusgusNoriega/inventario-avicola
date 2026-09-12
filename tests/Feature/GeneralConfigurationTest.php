<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\JourneyPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class GeneralConfigurationTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_guests_cannot_read_or_change_general_configuration(): void
    {
        $this->get('/configuracion-general')->assertRedirect('/login');
        $this->getJson('/api/v1/configuracion-general')->assertUnauthorized();
        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
        ])->assertUnauthorized();
    }

    public function test_unassigned_users_cannot_access_configuration_or_see_its_menu_tile(): void
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_DIRECTORIO']);
        $this->actingAs($user);

        $this->get('/configuracion-general')->assertForbidden();
        $this->get('/')->assertOk()->assertDontSee(route('configuracion-general'), false);

        Sanctum::actingAs($user, ['api']);
        $this->getJson('/api/v1/configuracion-general')->assertForbidden();
        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
        ])->assertForbidden();

        $this->assertDatabaseHas('empresas', [
            'id' => $user->empresa_id,
            'hora_corte_operativo' => '21:00:00',
        ]);
    }

    public function test_public_directory_mode_keeps_configuration_authenticated_and_module_protected(): void
    {
        config()->set('directory.public_access', true);
        Route::prefix('public-configuration-test')->group(base_path('routes/api.php'));
        $url = '/public-configuration-test/v1/configuracion-general';

        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url, [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
        ])->assertUnauthorized();

        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_DIRECTORIO']);
        Sanctum::actingAs($user, ['api']);

        $this->getJson($url)->assertForbidden();
        $this->putJson($url, [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
        ])->assertForbidden();

        $this->assertDatabaseHas('empresas', [
            'id' => $user->empresa_id,
            'hora_corte_operativo' => '21:00:00',
        ]);
    }

    public function test_inactive_users_cannot_read_or_update_configuration(): void
    {
        $user = $this->configurationUser();
        $user->update(['estado' => User::STATUS_INACTIVE]);
        Sanctum::actingAs($user, ['api']);

        $this->getJson('/api/v1/configuracion-general')->assertForbidden();
        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
        ])->assertForbidden();
    }

    public function test_users_must_change_their_required_password_before_editing_configuration(): void
    {
        $user = $this->configurationUser();
        $user->update(['debe_cambiar_password' => true]);
        $this->actingAs($user)->get('/configuracion-general')->assertRedirect(route('account'));
        Sanctum::actingAs($user, ['api']);

        $this->getJson('/api/v1/configuracion-general')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_authorized_user_without_a_branch_reads_the_current_company_default(): void
    {
        $user = $this->configurationUser();
        DB::table('empresas')->where('id', $user->empresa_id)->update([
            'razon_social' => 'Avicola de prueba',
            'nombre_comercial' => 'Avicola de prueba',
            'zona_horaria' => 'America/Lima',
        ]);
        $this->assertNull($user->sucursal_id);
        $this->assertDatabaseCount('sucursales', 0);
        Sanctum::actingAs($user, ['api']);

        $this->getJson('/api/v1/configuracion-general')
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Avicola de prueba')
            ->assertJsonPath('data.cutoff', '21:00')
            ->assertJsonPath('data.timezone', 'America/Lima');
    }

    public function test_module_holders_can_open_configuration_and_find_it_in_the_menu(): void
    {
        $user = $this->configurationUser();
        $this->actingAs($user);

        $this->get(route('configuracion-general'))->assertOk();
        $this->get('/')->assertOk()->assertSee(route('configuracion-general'), false);
    }

    public function test_administrators_can_edit_configuration_without_an_explicit_module_assignment(): void
    {
        $user = User::factory()->create();
        $role = $this->makeAdministrator($user);
        $this->assertSame(0, $role->permissions()->count());
        $this->actingAs($user);

        $this->get(route('configuracion-general'))->assertOk();
        $this->get('/')->assertOk()->assertSee(route('configuracion-general'), false);
        Sanctum::actingAs($user, ['api']);

        $this->getJson('/api/v1/configuracion-general')->assertOk();
        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
        ])->assertOk()->assertJsonPath('data.cutoff', '22:30');
    }

    public function test_access_managers_can_assign_the_configuration_module_to_a_role(): void
    {
        $manager = User::factory()->create();
        $this->grantModules($manager, ['MODULO_USUARIOS_ROLES']);
        Sanctum::actingAs($manager, ['api']);

        $this->getJson('/api/v1/admin/modules')
            ->assertOk()
            ->assertJsonFragment(['code' => 'MODULO_CONFIGURACION_GENERAL']);
        $response = $this->postJson('/api/v1/admin/roles', [
            'code' => 'CONFIGURACION',
            'name' => 'Configuracion de jornada',
            'module_codes' => ['MODULO_CONFIGURACION_GENERAL'],
        ])->assertCreated();

        $role = Role::query()->findOrFail($response->json('data.id'));
        $this->assertSame(['MODULO_CONFIGURACION_GENERAL'], $role->permissions()->pluck('codigo')->all());

        $operator = $this->createUserForCompany($manager);
        $operator->roles()->attach($role);
        Sanctum::actingAs($operator, ['api']);
        $this->getJson('/api/v1/configuracion-general')->assertOk();
    }

    #[DataProvider('validCutoffs')]
    public function test_valid_cutoff_is_saved_and_returned_by_a_fresh_read(string $cutoff): void
    {
        $user = $this->configurationUser();
        Sanctum::actingAs($user, ['api']);

        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => $cutoff,
            'expected_cutoff' => '21:00',
        ])->assertOk()->assertJsonPath('data.cutoff', $cutoff);

        $this->assertDatabaseHas('empresas', [
            'id' => $user->empresa_id,
            'hora_corte_operativo' => $cutoff.':00',
        ]);
        $this->getJson('/api/v1/configuracion-general')
            ->assertOk()
            ->assertJsonPath('data.cutoff', $cutoff);
    }

    public static function validCutoffs(): array
    {
        return [
            'evening' => ['22:30'],
            'midnight' => ['00:00'],
            'last minute' => ['23:59'],
        ];
    }

    #[DataProvider('invalidCutoffs')]
    public function test_invalid_or_missing_times_are_rejected_without_saving(array $payload, string $field): void
    {
        $user = $this->configurationUser();
        Sanctum::actingAs($user, ['api']);

        $this->putJson('/api/v1/configuracion-general', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('empresas', [
            'id' => $user->empresa_id,
            'hora_corte_operativo' => '21:00:00',
        ]);
    }

    public static function invalidCutoffs(): array
    {
        return [
            'missing cutoff' => [['expected_cutoff' => '21:00'], 'cutoff'],
            'missing expected cutoff' => [['cutoff' => '22:30'], 'expected_cutoff'],
            'null cutoff' => [['cutoff' => null, 'expected_cutoff' => '21:00'], 'cutoff'],
            'empty cutoff' => [['cutoff' => '', 'expected_cutoff' => '21:00'], 'cutoff'],
            'hour out of range' => [['cutoff' => '24:00', 'expected_cutoff' => '21:00'], 'cutoff'],
            'minute out of range' => [['cutoff' => '22:60', 'expected_cutoff' => '21:00'], 'cutoff'],
            'seconds included' => [['cutoff' => '22:30:00', 'expected_cutoff' => '21:00'], 'cutoff'],
            'hour not padded' => [['cutoff' => '9:00', 'expected_cutoff' => '21:00'], 'cutoff'],
            'twelve hour format' => [['cutoff' => '9:00 PM', 'expected_cutoff' => '21:00'], 'cutoff'],
            'expected cutoff seconds' => [['cutoff' => '22:30', 'expected_cutoff' => '21:00:00'], 'expected_cutoff'],
            'expected cutoff out of range' => [['cutoff' => '22:30', 'expected_cutoff' => '24:00'], 'expected_cutoff'],
        ];
    }

    public function test_company_inputs_cannot_read_or_change_another_company_configuration(): void
    {
        $user = $this->configurationUser();
        $otherUser = $this->configurationUser();
        DB::table('empresas')->where('id', $otherUser->empresa_id)->update([
            'hora_corte_operativo' => '19:15:00',
        ]);
        Sanctum::actingAs($user, ['api']);

        $this->getJson('/api/v1/configuracion-general?empresa_id='.$otherUser->empresa_id.'&company_id='.$otherUser->empresa_id)
            ->assertOk()
            ->assertJsonPath('data.cutoff', '21:00');
        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
            'empresa_id' => $otherUser->empresa_id,
            'company_id' => $otherUser->empresa_id,
        ])->assertOk()->assertJsonPath('data.cutoff', '22:30');

        $this->assertDatabaseHas('empresas', [
            'id' => $user->empresa_id,
            'hora_corte_operativo' => '22:30:00',
        ]);
        $this->assertDatabaseHas('empresas', [
            'id' => $otherUser->empresa_id,
            'hora_corte_operativo' => '19:15:00',
        ]);

        Sanctum::actingAs($otherUser, ['api']);
        $this->getJson('/api/v1/configuracion-general')->assertOk()->assertJsonPath('data.cutoff', '19:15');
    }

    public function test_a_stale_form_cannot_overwrite_a_more_recent_cutoff(): void
    {
        $user = $this->configurationUser();
        Sanctum::actingAs($user, ['api']);
        $this->getJson('/api/v1/configuracion-general')->assertOk()->assertJsonPath('data.cutoff', '21:00');

        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => '22:30',
            'expected_cutoff' => '21:00',
        ])->assertOk();
        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => '18:00',
            'expected_cutoff' => '21:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_cutoff');

        $this->assertDatabaseHas('empresas', [
            'id' => $user->empresa_id,
            'hora_corte_operativo' => '22:30:00',
        ]);
        $this->getJson('/api/v1/configuracion-general')->assertOk()->assertJsonPath('data.cutoff', '22:30');
    }

    #[DataProvider('journeyBoundaries')]
    public function test_saved_cutoff_controls_journey_start_and_end_at_the_boundary(
        string $cutoff,
        string $now,
        string $operatingDate,
        string $startsAt,
        string $endsAt,
    ): void {
        $user = $this->configurationUser();
        Sanctum::actingAs($user, ['api']);
        $this->putJson('/api/v1/configuracion-general', [
            'cutoff' => $cutoff,
            'expected_cutoff' => '21:00',
        ])->assertOk();

        CarbonImmutable::setTestNow(CarbonImmutable::parse($now, 'America/Lima'));
        $window = app(JourneyPlanService::class)->currentWindow(
            (int) $user->empresa_id,
            (object) ['zona_horaria' => 'America/Lima'],
        );

        $this->assertSame($operatingDate, $window['operating_date']->format('Y-m-d'));
        $this->assertSame($startsAt, $window['starts_at']->format('Y-m-d H:i:s'));
        $this->assertSame($endsAt, $window['ends_at']->format('Y-m-d H:i:s'));
        $this->assertSame($cutoff.':00', $window['cutoff']);
    }

    public static function journeyBoundaries(): array
    {
        return [
            'before evening cutoff' => ['22:30', '2026-09-12 22:29:59', '2026-09-12', '2026-09-11 22:30:00', '2026-09-12 22:30:00'],
            'at evening cutoff' => ['22:30', '2026-09-12 22:30:00', '2026-09-13', '2026-09-12 22:30:00', '2026-09-13 22:30:00'],
            'after evening cutoff' => ['22:30', '2026-09-12 22:30:01', '2026-09-13', '2026-09-12 22:30:00', '2026-09-13 22:30:00'],
            'before midnight cutoff' => ['00:00', '2026-09-11 23:59:59', '2026-09-12', '2026-09-11 00:00:00', '2026-09-12 00:00:00'],
            'at midnight cutoff' => ['00:00', '2026-09-12 00:00:00', '2026-09-13', '2026-09-12 00:00:00', '2026-09-13 00:00:00'],
            'after midnight cutoff' => ['00:00', '2026-09-12 00:00:01', '2026-09-13', '2026-09-12 00:00:00', '2026-09-13 00:00:00'],
        ];
    }

    private function configurationUser(): User
    {
        $user = User::factory()->create();
        $this->grantModules($user, ['MODULO_CONFIGURACION_GENERAL']);

        return $user;
    }
}
