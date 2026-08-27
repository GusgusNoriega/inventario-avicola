<?php

namespace Tests\Unit;

use App\Models\Empresa;
use App\Services\LiveChickenReceptionJourneyReportImageRenderer;
use Tests\TestCase;

class LiveChickenReceptionJourneyReportImageRendererTest extends TestCase
{
    public function test_it_renders_every_weighing_across_numbered_png_pages_and_keeps_the_summary_at_the_end(): void
    {
        $company = new Empresa([
            'razon_social' => 'Avícola de prueba S.A.C.',
            'nombre_comercial' => 'Avícola de prueba',
        ]);
        $records = array_map(
            fn (int $index): array => $this->record($index),
            range(1, 25),
        );

        $pages = app(LiveChickenReceptionJourneyReportImageRenderer::class)->render(
            $company,
            $this->report($records),
        );

        $this->assertCount(2, $pages);
        foreach ($pages as $page) {
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $page);
            $size = getimagesizefromstring($page);
            $this->assertIsArray($size);
            $this->assertSame(1980, $size[0]);
            $this->assertSame(1400, $size[1]);
            $this->assertSame('image/png', $size['mime']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function report(array $records): array
    {
        return [
            'branch' => [
                'id' => 1,
                'name' => 'Sucursal principal',
                'timezone' => 'America/Bogota',
            ],
            'journey' => [
                'id' => 7,
                'operating_date' => '2026-08-27',
                'status' => 'ABIERTA',
                'starts_at' => '2026-08-27T05:00:00-05:00',
                'ends_at' => '2026-08-28T05:00:00-05:00',
            ],
            'generated_at' => '2026-08-27T16:00:00-05:00',
            'records' => $records,
            'summary' => [
                'own' => $this->summary(13),
                'external' => $this->summary(12),
                'total' => $this->summary(25),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function record(int $index): array
    {
        return [
            'source' => $index % 3 === 0 ? 'TICKET' : 'RECEPCION',
            'number' => $index,
            'weighed_at' => sprintf('2026-08-27T%02d:15:00-05:00', 6 + ($index % 12)),
            'ticket' => ['code' => 'T-RPV-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)],
            'owner' => ['name' => $index % 2 === 0 ? 'Empresa externa de prueba' : 'Mi empresa'],
            'sex' => $index % 2 === 0 ? 'HEMBRA' : 'MACHO',
            'destination' => ['name' => 'Almacén '.$index],
            'cage_type' => ['name' => 'Java de 7 kg'],
            'birds_per_cage' => 7,
            'cages' => 2,
            'birds' => 14,
            'gross_weight_kg' => 100 + $index,
            'tare_weight_kg' => 14,
            'net_weight_kg' => 86 + $index,
        ];
    }

    /** @return array<string, int|float> */
    private function summary(int $weighings): array
    {
        return [
            'weighings' => $weighings,
            'cages' => $weighings * 2,
            'birds' => $weighings * 14,
            'gross_weight_kg' => $weighings * 100,
            'tare_weight_kg' => $weighings * 14,
            'net_weight_kg' => $weighings * 86,
            'average_weight_per_bird_kg' => 6.143,
        ];
    }
}
