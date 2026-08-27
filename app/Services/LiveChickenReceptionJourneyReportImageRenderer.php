<?php

namespace App\Services;

use App\Models\Empresa;
use Carbon\CarbonImmutable;
use GdImage;
use RuntimeException;

class LiveChickenReceptionJourneyReportImageRenderer
{
    private const WIDTH = 1980;

    private const HEIGHT = 1400;

    private const MARGIN = 44;

    private const ROW_HEIGHT = 43;

    private string $regularFont;

    private string $boldFont;

    public function __construct()
    {
        $fontDirectory = base_path('vendor/dompdf/dompdf/lib/fonts');
        $this->regularFont = $fontDirectory.'/DejaVuSans.ttf';
        $this->boldFont = $fontDirectory.'/DejaVuSans-Bold.ttf';
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    public function render(Empresa $company, array $report): array
    {
        if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
            throw new RuntimeException('La extensión GD con soporte FreeType es necesaria para generar las imágenes.');
        }

        foreach ([$this->regularFont, $this->boldFont] as $font) {
            if (! is_file($font)) {
                throw new RuntimeException('No se encontró la tipografía necesaria para generar las imágenes.');
            }
        }

        $records = array_values(is_array($report['records'] ?? null) ? $report['records'] : []);
        $pages = $this->paginate($records);
        $pageCount = count($pages);

        return array_map(
            fn (array $page, int $index): string => $this->renderPage(
                $company,
                $report,
                $page['records'],
                $page['summary'],
                $index + 1,
                $pageCount,
            ),
            $pages,
            array_keys($pages),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array{records: list<array<string, mixed>>, summary: bool}>
     */
    private function paginate(array $records): array
    {
        $pages = [];
        $remaining = $records;
        $firstPage = true;

        do {
            $capacity = $firstPage ? 20 : 26;
            $pages[] = [
                'records' => array_splice($remaining, 0, $capacity),
                'summary' => false,
            ];
            $firstPage = false;
        } while ($remaining !== []);

        $lastIndex = array_key_last($pages);
        $summaryCapacity = $lastIndex === 0 ? 12 : 18;
        if (count($pages[$lastIndex]['records']) <= $summaryCapacity) {
            $pages[$lastIndex]['summary'] = true;
        } else {
            $pages[] = ['records' => [], 'summary' => true];
        }

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $records
     */
    private function renderPage(
        Empresa $company,
        array $report,
        array $records,
        bool $showSummary,
        int $page,
        int $pageCount,
    ): string {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('No se pudo preparar la imagen del reporte.');
        }

        $colors = $this->palette($image);
        imagefill($image, 0, 0, $colors['background']);

        $summaryOnly = $page > 1 && $records === [] && $showSummary;
        $cursorY = $this->drawHeader($image, $company, $report, $page, $summaryOnly, $colors);
        if (! $summaryOnly) {
            $cursorY = $this->drawTable($image, $report, $records, $cursorY, $colors);
        }

        if ($showSummary) {
            $this->drawSummary($image, $report, $cursorY + 26, $colors);
        }

        $footer = sprintf(
            'Solo pesadas activas · Página %d de %d · Generado %s',
            $page,
            $pageCount,
            $this->formatGeneratedAt($report),
        );
        $this->centerText($image, $footer, 11, self::HEIGHT - 22, $colors['muted'], false);

        ob_start();
        imagepng($image, null, 7);
        $contents = ob_get_clean();
        imagedestroy($image);

        if (! is_string($contents)) {
            throw new RuntimeException('No se pudo codificar la imagen del reporte.');
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, int>  $colors
     */
    private function drawHeader(
        GdImage $image,
        Empresa $company,
        array $report,
        int $page,
        bool $summaryOnly,
        array $colors,
    ): int {
        if ($page > 1) {
            $this->centerText(
                $image,
                $summaryOnly
                    ? 'REPORTE DE RECEPCIÓN DE POLLO VIVO · RESUMEN FINAL'
                    : 'REPORTE DE RECEPCIÓN DE POLLO VIVO · CONTINUACIÓN',
                19,
                52,
                $colors['primary'],
                true,
            );

            return 78;
        }

        $companyName = trim((string) ($company->nombre_comercial ?: $company->razon_social));
        $this->centerText($image, $companyName, 14, 40, $colors['muted'], false);
        $this->centerText($image, 'REPORTE COMPLETO DE RECEPCIÓN DE POLLO VIVO', 25, 82, $colors['primary'], true);

        $journeyDate = $this->formatOperatingDate((string) data_get($report, 'journey.operating_date', ''));
        $branch = (string) data_get($report, 'branch.name', 'Sucursal');
        $status = $this->statusLabel((string) data_get($report, 'journey.status', ''));
        $subtitle = "Jornada: {$journeyDate} · Sucursal: {$branch} · Estado: {$status}";
        $this->centerText($image, $subtitle, 14, 120, $colors['ink'], false);

        imagefilledrectangle($image, self::MARGIN, 144, self::WIDTH - self::MARGIN, 188, $colors['notice_bg']);
        $this->drawCellText(
            $image,
            'Este reporte contiene exclusivamente todas las pesadas activas de la jornada seleccionada.',
            self::MARGIN,
            144,
            self::WIDTH - (self::MARGIN * 2),
            44,
            12,
            $colors['primary'],
            true,
            'center',
        );

        return 210;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, int>  $colors
     */
    private function drawTable(
        GdImage $image,
        array $report,
        array $records,
        int $cursorY,
        array $colors,
    ): int {
        $columns = [
            ['label' => 'BRUTO KG', 'width' => .08, 'align' => 'right'],
            ['label' => 'TARA KG', 'width' => .07, 'align' => 'right'],
            ['label' => 'NETO KG', 'width' => .08, 'align' => 'right'],
            ['label' => 'JAVAS', 'width' => .05, 'align' => 'right'],
            ['label' => 'POLLOS', 'width' => .055, 'align' => 'right'],
            ['label' => 'AVES/J.', 'width' => .055, 'align' => 'right'],
            ['label' => 'SEXO', 'width' => .05, 'align' => 'center'],
            ['label' => 'PROPIETARIO', 'width' => .10, 'align' => 'left'],
            ['label' => 'DESTINO', 'width' => .10, 'align' => 'left'],
            ['label' => 'TIPO JAVA', 'width' => .085, 'align' => 'left'],
            ['label' => 'FECHA / HORA', 'width' => .10, 'align' => 'left'],
            ['label' => 'ORIGEN', 'width' => .07, 'align' => 'left'],
            ['label' => 'REGISTRO', 'width' => .105, 'align' => 'left'],
        ];
        $tableWidth = self::WIDTH - (self::MARGIN * 2);
        $headerHeight = 48;
        imagefilledrectangle($image, self::MARGIN, $cursorY, self::MARGIN + $tableWidth, $cursorY + $headerHeight, $colors['primary']);
        $x = self::MARGIN;
        foreach ($columns as $column) {
            $columnWidth = (int) round($tableWidth * $column['width']);
            imagerectangle($image, $x, $cursorY, $x + $columnWidth, $cursorY + $headerHeight, $colors['primary_dark']);
            $this->drawCellText(
                $image,
                $column['label'],
                $x,
                $cursorY,
                $columnWidth,
                $headerHeight,
                9,
                $colors['white'],
                true,
                'center',
            );
            $x += $columnWidth;
        }
        $cursorY += $headerHeight;

        if ($records === []) {
            $this->drawCellText(
                $image,
                'No hay pesadas activas en esta página.',
                self::MARGIN,
                $cursorY,
                $tableWidth,
                70,
                14,
                $colors['muted'],
                false,
                'center',
            );

            return $cursorY + 70;
        }

        foreach ($records as $index => $record) {
            $rowBackground = $index % 2 === 0 ? $colors['white'] : $colors['row_alt'];
            imagefilledrectangle($image, self::MARGIN, $cursorY, self::MARGIN + $tableWidth, $cursorY + self::ROW_HEIGHT, $rowBackground);
            $cells = $this->recordCells($report, $record);
            $x = self::MARGIN;
            foreach ($columns as $cellIndex => $column) {
                $columnWidth = (int) round($tableWidth * $column['width']);
                imagerectangle($image, $x, $cursorY, $x + $columnWidth, $cursorY + self::ROW_HEIGHT, $colors['border']);
                $this->drawCellText(
                    $image,
                    $cells[$cellIndex] ?? '-',
                    $x,
                    $cursorY,
                    $columnWidth,
                    self::ROW_HEIGHT,
                    9,
                    $cellIndex === 2 ? $colors['accent'] : $colors['ink'],
                    $cellIndex === 2,
                    $column['align'],
                );
                $x += $columnWidth;
            }
            $cursorY += self::ROW_HEIGHT;
        }

        return $cursorY;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function recordCells(array $report, array $record): array
    {
        $timezone = (string) data_get($report, 'branch.timezone', config('app.timezone'));
        $source = strtoupper((string) ($record['source'] ?? ''));
        $registration = $source === 'TICKET'
            ? (string) data_get($record, 'ticket.code', 'Ticket')
            : 'Recep. #'.(int) ($record['number'] ?? 0);
        $origin = $source === 'TICKET' ? 'Ticket' : 'Recepción';

        return [
            $this->weight($record['gross_weight_kg'] ?? 0),
            $this->weight($record['tare_weight_kg'] ?? 0),
            $this->weight($record['net_weight_kg'] ?? 0),
            number_format((int) ($record['cages'] ?? 0), 0, '.', ','),
            number_format((int) ($record['birds'] ?? 0), 0, '.', ','),
            number_format((int) ($record['birds_per_cage'] ?? 0), 0, '.', ','),
            $this->shortSex((string) ($record['sex'] ?? '')),
            (string) data_get($record, 'owner.name', 'Sin propietario'),
            (string) data_get($record, 'destination.name', 'Sin destino'),
            (string) (data_get($record, 'cage_type.name') ?: data_get($record, 'cage_type.code', 'Sin tipo')),
            $this->formatWeighedAt($record['weighed_at'] ?? null, $timezone),
            $origin,
            $registration,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, int>  $colors
     */
    private function drawSummary(GdImage $image, array $report, int $cursorY, array $colors): void
    {
        $cursorY = min($cursorY, self::HEIGHT - 350);
        $this->drawCellText(
            $image,
            'RESUMEN DETALLADO DE TOTALES ACTIVOS',
            self::MARGIN,
            $cursorY,
            self::WIDTH - (self::MARGIN * 2),
            38,
            15,
            $colors['primary'],
            true,
            'left',
        );
        $cursorY += 46;

        $cards = [
            ['key' => 'own', 'label' => 'MI EMPRESA', 'color' => $colors['accent']],
            ['key' => 'external', 'label' => 'EMPRESA EXTERNA', 'color' => $colors['external']],
            ['key' => 'total', 'label' => 'TOTAL GENERAL', 'color' => $colors['total']],
        ];
        $gap = 20;
        $cardWidth = (int) floor((self::WIDTH - (self::MARGIN * 2) - ($gap * 2)) / 3);
        $cardHeight = 244;

        foreach ($cards as $index => $card) {
            $x = self::MARGIN + (($cardWidth + $gap) * $index);
            imagefilledrectangle($image, $x, $cursorY, $x + $cardWidth, $cursorY + $cardHeight, $colors['white']);
            imagerectangle($image, $x, $cursorY, $x + $cardWidth, $cursorY + $cardHeight, $colors['border']);
            imagefilledrectangle($image, $x, $cursorY, $x + 8, $cursorY + $cardHeight, $card['color']);
            $summary = is_array(data_get($report, 'summary.'.$card['key']))
                ? data_get($report, 'summary.'.$card['key'])
                : [];
            $this->drawCellText($image, $card['label'], $x + 18, $cursorY + 8, $cardWidth - 28, 30, 13, $card['color'], true, 'left');
            $this->drawCellText(
                $image,
                sprintf(
                    'Pesadas: %s   ·   Javas: %s   ·   Pollos: %s',
                    number_format((int) ($summary['weighings'] ?? 0), 0, '.', ','),
                    number_format((int) ($summary['cages'] ?? 0), 0, '.', ','),
                    number_format((int) ($summary['birds'] ?? 0), 0, '.', ','),
                ),
                $x + 18,
                $cursorY + 42,
                $cardWidth - 28,
                26,
                10,
                $colors['ink'],
                false,
                'left',
            );
            $this->drawCellText(
                $image,
                'Pollos macho: '.number_format((int) ($summary['male_birds'] ?? 0), 0, '.', ','),
                $x + 18,
                $cursorY + 69,
                $cardWidth - 28,
                24,
                10,
                $colors['ink'],
                false,
                'left',
            );
            $this->drawCellText(
                $image,
                'Pollos hembra: '.number_format((int) ($summary['female_birds'] ?? 0), 0, '.', ','),
                $x + 18,
                $cursorY + 93,
                $cardWidth - 28,
                24,
                10,
                $colors['ink'],
                false,
                'left',
            );
            $this->drawCellText(
                $image,
                'Peso bruto: '.$this->weight($summary['gross_weight_kg'] ?? 0).' kg',
                $x + 18,
                $cursorY + 117,
                $cardWidth - 28,
                26,
                10,
                $colors['ink'],
                false,
                'left',
            );
            $this->drawCellText(
                $image,
                'Tara: '.$this->weight($summary['tare_weight_kg'] ?? 0).' kg',
                $x + 18,
                $cursorY + 143,
                $cardWidth - 28,
                26,
                10,
                $colors['ink'],
                false,
                'left',
            );
            $this->drawCellText(
                $image,
                'Peso neto: '.$this->weight($summary['net_weight_kg'] ?? 0).' kg',
                $x + 18,
                $cursorY + 169,
                $cardWidth - 28,
                32,
                12,
                $card['color'],
                true,
                'left',
            );
            $this->drawCellText(
                $image,
                'Promedio por pollo: '.$this->weight($summary['average_weight_per_bird_kg'] ?? 0).' kg',
                $x + 18,
                $cursorY + 204,
                $cardWidth - 28,
                30,
                9,
                $colors['muted'],
                false,
                'left',
            );
        }
    }

    /** @return array<string, int> */
    private function palette(GdImage $image): array
    {
        return [
            'background' => $this->color($image, '#F4F8F7'),
            'white' => $this->color($image, '#FFFFFF'),
            'primary' => $this->color($image, '#123F3D'),
            'primary_dark' => $this->color($image, '#092B2A'),
            'ink' => $this->color($image, '#193331'),
            'muted' => $this->color($image, '#637977'),
            'border' => $this->color($image, '#CBDAD7'),
            'row_alt' => $this->color($image, '#EEF5F3'),
            'notice_bg' => $this->color($image, '#DFF4EA'),
            'accent' => $this->color($image, '#148B60'),
            'external' => $this->color($image, '#B56E10'),
            'total' => $this->color($image, '#2563A7'),
        ];
    }

    private function color(GdImage $image, string $hex): int
    {
        $hex = ltrim($hex, '#');
        $color = imagecolorallocate(
            $image,
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );

        if ($color === false) {
            throw new RuntimeException('No se pudo preparar la paleta de la imagen.');
        }

        return $color;
    }

    private function centerText(
        GdImage $image,
        string $text,
        int $size,
        int $baseline,
        int $color,
        bool $bold,
    ): void {
        $font = $bold ? $this->boldFont : $this->regularFont;
        $box = imagettfbbox($size, 0, $font, $text);
        $textWidth = abs($box[4] - $box[0]);
        imagettftext($image, $size, 0, (int) ((self::WIDTH - $textWidth) / 2), $baseline, $color, $font, $text);
    }

    private function drawCellText(
        GdImage $image,
        string $text,
        int $x,
        int $y,
        int $width,
        int $height,
        int $size,
        int $color,
        bool $bold,
        string $align,
    ): void {
        $font = $bold ? $this->boldFont : $this->regularFont;
        $text = $this->fitText($text, $font, $size, $width - 12);
        $box = imagettfbbox($size, 0, $font, $text);
        $textWidth = abs($box[4] - $box[0]);
        $textX = match ($align) {
            'right' => $x + $width - $textWidth - 6,
            'center' => $x + (int) (($width - $textWidth) / 2),
            default => $x + 6,
        };
        $baseline = $y + (int) (($height + $size) / 2) - 1;
        imagettftext($image, $size, 0, $textX, $baseline, $color, $font, $text);
    }

    private function fitText(string $text, string $font, int $size, int $maxWidth): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text));
        $text = $normalized === null || $normalized === '' ? '-' : $normalized;
        if ($this->textWidth($text, $font, $size) <= $maxWidth) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $this->textWidth($text.'…', $font, $size) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return abs($box[4] - $box[0]);
    }

    private function formatWeighedAt(mixed $value, string $timezone): string
    {
        if (! filled($value)) {
            return '-';
        }

        return CarbonImmutable::parse((string) $value)
            ->timezone($timezone ?: config('app.timezone'))
            ->format('d/m/Y H:i');
    }

    private function formatOperatingDate(string $value): string
    {
        if ($value === '') {
            return 'Sin fecha';
        }

        return CarbonImmutable::parse($value)->format('d/m/Y');
    }

    /** @param  array<string, mixed>  $report */
    private function formatGeneratedAt(array $report): string
    {
        $value = $report['generated_at'] ?? null;
        if (! filled($value)) {
            return now()->format('d/m/Y H:i');
        }

        return CarbonImmutable::parse((string) $value)
            ->timezone((string) data_get($report, 'branch.timezone', config('app.timezone')))
            ->format('d/m/Y H:i');
    }

    private function statusLabel(string $status): string
    {
        return match (strtoupper($status)) {
            'ABIERTA' => 'Abierta',
            'CERRADA' => 'Cerrada',
            default => $status !== '' ? ucfirst(mb_strtolower($status)) : 'Sin estado',
        };
    }

    private function shortSex(string $sex): string
    {
        return match (strtoupper($sex)) {
            'MACHO' => 'Macho',
            'HEMBRA' => 'Hembra',
            default => $sex !== '' ? ucfirst(mb_strtolower($sex)) : '-',
        };
    }

    private function weight(mixed $value): string
    {
        return number_format((float) $value, 3, '.', ',');
    }
}
