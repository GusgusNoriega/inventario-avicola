<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\RecepcionPolloVivo;
use App\Models\User;
use App\Services\LiveChickenReceptionJourneyReportImageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;
use ZipArchive;

class LiveChickenReceptionJourneyReportWebTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $journeyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->branchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'REPORTE-WEB',
            'Sucursal reporte web',
        );
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->grantModules($this->user, ['MODULO_RECEPCION_POLLO_VIVO']);
        $this->journeyId = $this->createJourney($this->branchId, '2026-08-26', (int) $this->user->id);
        $this->createReception($this->journeyId, (int) $this->user->id);
        $this->actingAs($this->user);
    }

    public function test_pdf_download_is_a_landscape_report_scoped_to_the_authenticated_context(): void
    {
        $response = $this->get(route('recepcion-pollo-vivo.historial.report.pdf', [
            'journey_id' => $this->journeyId,
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="'.$this->basename().'.pdf"',
            )
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pdf_can_be_previewed_inline_for_the_selected_journey(): void
    {
        $response = $this->get(route('recepcion-pollo-vivo.historial.report.pdf', [
            'journey_id' => $this->journeyId,
            'preview' => 1,
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'inline; filename="'.$this->basename().'.pdf"',
            )
            ->assertHeader('Cache-Control');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_single_report_image_is_downloaded_as_png(): void
    {
        $response = $this->get(route('recepcion-pollo-vivo.historial.report.images', [
            'journey_id' => $this->journeyId,
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="'.$this->basename().'.png"',
            );
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($response->getContent(), 0, 8));
    }

    public function test_multiple_report_images_are_downloaded_in_a_numbered_zip(): void
    {
        $this->mock(
            LiveChickenReceptionJourneyReportImageRenderer::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('render')
                    ->once()
                    ->withArgs(fn (Empresa $company, array $report): bool => (int) $company->id === (int) $this->user->empresa_id
                        && (int) data_get($report, 'branch.id') === $this->branchId
                        && (int) data_get($report, 'journey.id') === $this->journeyId)
                    ->andReturn(['primera-imagen', 'segunda-imagen']);
            },
        );

        $response = $this->get(route('recepcion-pollo-vivo.historial.report.images', [
            'journey_id' => $this->journeyId,
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="'.$this->basename().'-imagenes.zip"',
            );

        $temporaryFile = tempnam(sys_get_temp_dir(), 'live-reception-report-test-');
        $this->assertNotFalse($temporaryFile);
        file_put_contents($temporaryFile, $response->getContent());
        $zip = new ZipArchive;

        try {
            $this->assertTrue($zip->open($temporaryFile) === true);
            $this->assertSame('primera-imagen', $zip->getFromName($this->basename().'-pagina-01.png'));
            $this->assertSame('segunda-imagen', $zip->getFromName($this->basename().'-pagina-02.png'));
            $this->assertSame(2, $zip->numFiles);
        } finally {
            $zip->close();
            @unlink($temporaryFile);
        }
    }

    public function test_downloads_require_a_valid_journey_id_and_the_reception_module(): void
    {
        $this->getJson(route('recepcion-pollo-vivo.historial.report.pdf'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('journey_id');
        $this->getJson(route('recepcion-pollo-vivo.historial.report.images', ['journey_id' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('journey_id');
        $this->getJson(route('recepcion-pollo-vivo.historial.report.pdf', [
            'journey_id' => $this->journeyId,
            'preview' => 'invalid',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preview');

        $unauthorized = $this->createUserForCompany($this->user, [
            'sucursal_id' => $this->branchId,
        ]);
        $this->actingAs($unauthorized);

        $this->get(route('recepcion-pollo-vivo.historial.report.pdf', ['journey_id' => $this->journeyId]))
            ->assertForbidden();
        $this->get(route('recepcion-pollo-vivo.historial.report.images', ['journey_id' => $this->journeyId]))
            ->assertForbidden();

        auth()->logout();
        $this->get(route('recepcion-pollo-vivo.historial.report.pdf', ['journey_id' => $this->journeyId]))
            ->assertRedirect(route('login'));
    }

    public function test_downloads_hide_journeys_from_other_branches_and_companies(): void
    {
        $otherBranchId = $this->createBranch(
            (int) $this->user->empresa_id,
            'OTRA-WEB',
            'Otra sucursal web',
        );
        $otherBranchJourney = $this->createJourney($otherBranchId, '2026-08-26', (int) $this->user->id);
        $this->createReception($otherBranchJourney, (int) $this->user->id);

        $foreignUser = User::factory()->create();
        $foreignBranchId = $this->createBranch(
            (int) $foreignUser->empresa_id,
            'AJENA-WEB',
            'Sucursal ajena web',
        );
        $foreignJourney = $this->createJourney($foreignBranchId, '2026-08-26', (int) $foreignUser->id);
        $this->createReception($foreignJourney, (int) $foreignUser->id);

        foreach ([$otherBranchJourney, $foreignJourney] as $journeyId) {
            $this->get(route('recepcion-pollo-vivo.historial.report.pdf', ['journey_id' => $journeyId]))
                ->assertNotFound();
            $this->get(route('recepcion-pollo-vivo.historial.report.images', ['journey_id' => $journeyId]))
                ->assertNotFound();
        }
    }

    private function basename(): string
    {
        return "recepcion-pollo-vivo-jornada-{$this->journeyId}-2026-08-26";
    }

    private function createBranch(int $companyId, string $code, string $name): int
    {
        return DB::table('sucursales')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'zona_horaria' => 'America/Bogota',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createJourney(int $branchId, string $date, int $actorId): int
    {
        return DB::table('jornadas_operativas')->insertGetId([
            'sucursal_id' => $branchId,
            'fecha_operativa' => $date,
            'estado' => 'CERRADA',
            'abierta_por' => $actorId,
            'inicio_at' => "{$date} 00:00:00",
            'cierre_programado_at' => "{$date} 21:00:00",
            'cerrada_por' => $actorId,
            'cerrada_at' => "{$date} 21:00:00",
        ]);
    }

    private function createReception(int $journeyId, int $actorId): int
    {
        return DB::table('recepciones_pollo_vivo')->insertGetId([
            'jornada_id' => $journeyId,
            'origen' => RecepcionPolloVivo::ORIGIN_DAILY_TRUCK,
            'estado' => RecepcionPolloVivo::STATUS_OPEN,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
