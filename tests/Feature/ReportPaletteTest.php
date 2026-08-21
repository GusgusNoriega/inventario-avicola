<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use App\Services\ReportPaletteService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ReportPaletteTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->makeAdministrator($this->user);
        $this->actingAs($this->user);
    }

    public function test_reports_page_exposes_the_company_palette_dialog_to_administrators(): void
    {
        $response = $this->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertSee('Configurar colores')
            ->assertSee('Vista previa del reporte');

        $document = new \DOMDocument('1.0', 'UTF-8');
        $this->assertTrue(@$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent()));
        $xpath = new \DOMXPath($document);
        $dialog = $xpath->query('//*[@id="reportPaletteDialog"]')?->item(0);

        $this->assertInstanceOf(\DOMElement::class, $dialog);
        $form = $xpath->query('.//form[@action="'.route('finanzas.reportes.palette.update').'"]', $dialog)?->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form);
        $this->assertSame(
            count(ReportPaletteService::DEFAULTS),
            $xpath->query('.//input[@type="color" and starts-with(@name, "colors[")]', $form)?->length,
        );
        $this->assertSame('PUT', $xpath->query('.//input[@name="_method"]', $form)?->item(0)?->getAttribute('value'));

        foreach (ReportPaletteService::DEFAULTS as $key => $value) {
            $input = $xpath->query('.//input[@name="colors['.$key.']"]', $form)?->item(0);
            $this->assertInstanceOf(\DOMElement::class, $input);
            $this->assertSame($value, strtoupper($input->getAttribute('value')));
        }
    }

    public function test_only_an_administrator_can_update_the_report_palette(): void
    {
        $restricted = $this->createUserForCompany($this->user);
        $this->grantModules($restricted, ['MODULO_FINANZAS']);

        $this->actingAs($restricted)
            ->put(route('finanzas.reportes.palette.update'), ['colors' => $this->customPalette()])
            ->assertForbidden();

        $this->actingAs($restricted)
            ->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertDontSee('Configurar colores')
            ->assertDontSee('reportPaletteDialog', false);

        $this->assertNull(Empresa::query()->findOrFail($this->user->empresa_id)->paleta_reportes);
    }

    public function test_palette_is_normalized_and_saved_only_for_the_authenticated_company(): void
    {
        $otherUser = User::factory()->create();
        $otherCompany = Empresa::query()->findOrFail($otherUser->empresa_id);
        $otherPalette = [...ReportPaletteService::DEFAULTS, 'primary' => '#112233'];
        $otherCompany->update(['paleta_reportes' => $otherPalette]);

        $submitted = array_map(strtolower(...), $this->customPalette());
        $this->put(route('finanzas.reportes.palette.update'), ['colors' => $submitted])
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHas('report_palette_status');

        $company = Empresa::query()->findOrFail($this->user->empresa_id);
        $this->assertSame($this->customPalette(), $company->paleta_reportes);
        $this->assertSame($otherPalette, $otherCompany->fresh()->paleta_reportes);

        $this->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertSee('#4A2A1B');
    }

    public function test_palette_rejects_unsafe_values_and_unreadable_contrast_without_saving(): void
    {
        $unsafe = $this->customPalette();
        $unsafe['credit'] = 'red; background: url(https://invalid.test)';

        $this->from(route('finanzas.reportes'))
            ->put(route('finanzas.reportes.palette.update'), ['colors' => $unsafe])
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrorsIn('reportPalette', ['colors.credit']);

        $unreadable = $this->customPalette();
        $unreadable['primary_text'] = $unreadable['primary'];

        $this->from(route('finanzas.reportes'))
            ->put(route('finanzas.reportes.palette.update'), ['colors' => $unreadable])
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrorsIn('reportPalette', ['colors.primary_text']);

        $unreadableAccent = $this->customPalette();
        $unreadableAccent['debit'] = $unreadableAccent['accent'];

        $this->from(route('finanzas.reportes'))
            ->put(route('finanzas.reportes.palette.update'), ['colors' => $unreadableAccent])
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrorsIn('reportPalette', ['colors.debit']);

        $this->assertNull(Empresa::query()->findOrFail($this->user->empresa_id)->paleta_reportes);
    }

    public function test_both_pdf_templates_receive_the_same_semantic_palette(): void
    {
        $palette = $this->customPalette();
        $common = [
            'company' => $this->user->empresa,
            'reportPalette' => $palette,
            'generatedAt' => CarbonImmutable::parse('2026-08-21 10:00:00'),
        ];
        $generalHtml = view('reports.pdf', [
            ...$common,
            'type' => 'pagos',
            'title' => 'Reporte de pagos y cobros',
            'from' => '2026-08-20',
            'to' => '2026-08-21',
            'data' => [
                'rows' => collect(),
                'income' => 0.0,
                'expense' => 0.0,
                'total' => 0.0,
            ],
        ])->render();
        $routeHtml = view('reports.collection-route-two', [
            ...$common,
            'data' => ['customers' => []],
        ])->render();

        foreach ($palette as $color) {
            $this->assertStringContainsString($color, $generalHtml);
            $this->assertStringContainsString($color, $routeHtml);
        }
        foreach (['#FFFF00', '#0000FF', '#FF0000'] as $legacyColor) {
            $this->assertStringNotContainsString($legacyColor, strtoupper($routeHtml));
        }
    }

    public function test_custom_palette_is_used_while_generating_pdf_and_png_outputs(): void
    {
        Empresa::query()->findOrFail($this->user->empresa_id)->update([
            'paleta_reportes' => $this->customPalette(),
        ]);

        $this->get(route('finanzas.reportes.pdf', [
            'type' => 'pagos',
            'desde' => '2026-08-20',
            'hasta' => '2026-08-21',
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('finanzas.reportes.pdf', [
            'type' => 'ruta-cobranza-2',
            'fecha' => '2026-08-21',
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $imageResponse = $this->get(route('finanzas.reportes.imagen', [
            'type' => 'pagos',
            'desde' => '2026-08-20',
            'hasta' => '2026-08-21',
        ]))->assertOk();
        $image = imagecreatefromstring($imageResponse->getContent());

        $this->assertInstanceOf(\GdImage::class, $image);
        $pixel = imagecolorat($image, 50, 318);
        $this->assertIsInt($pixel);
        $this->assertSame([210, 180, 140], [
            ($pixel >> 16) & 0xFF,
            ($pixel >> 8) & 0xFF,
            $pixel & 0xFF,
        ]);
        imagedestroy($image);
    }

    /** @return array<string, string> */
    private function customPalette(): array
    {
        return [
            'page_background' => '#FFFDF8',
            'primary' => '#4A2A1B',
            'primary_text' => '#FFFFFF',
            'secondary' => '#D2B48C',
            'secondary_text' => '#1F1712',
            'accent' => '#F2E8DC',
            'body_text' => '#211A16',
            'muted_text' => '#5A514B',
            'border' => '#9A8D82',
            'debit' => '#1557A0',
            'credit' => '#9C251F',
        ];
    }
}
