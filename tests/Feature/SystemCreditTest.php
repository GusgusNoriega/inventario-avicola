<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class SystemCreditTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    public function test_login_and_menu_pages_show_the_system_credit(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('class="system-credit"', false)
            ->assertSee('Sistema realizado por')
            ->assertSee('Gustavo Noriega')
            ->assertSee('WhatsApp')
            ->assertSee('949 421 023');

        $user = User::factory()->create();
        $this->makeAdministrator($user);
        $this->actingAs($user);

        foreach (['/', '/finanzas'] as $path) {
            $response = $this->get($path)
                ->assertOk()
                ->assertSee('class="system-credit"', false)
                ->assertSee('Sistema realizado por')
                ->assertSee('Gustavo Noriega')
                ->assertSee('WhatsApp')
                ->assertSee('949 421 023');

            $this->assertSame(1, substr_count($response->getContent(), 'class="system-credit"'));
        }
    }
}
