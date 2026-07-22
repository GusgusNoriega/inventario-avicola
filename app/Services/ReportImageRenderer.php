<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use GdImage;
use RuntimeException;

class ReportImageRenderer
{
    private string $regularFont;

    private string $boldFont;

    public function __construct()
    {
        $fontDirectory = base_path('vendor/dompdf/dompdf/lib/fonts');
        $this->regularFont = $fontDirectory.'/DejaVuSans.ttf';
        $this->boldFont = $fontDirectory.'/DejaVuSans-Bold.ttf';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function render(array $payload): array
    {
        if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
            throw new RuntimeException('La extension GD con soporte FreeType es necesaria para generar imagenes.');
        }

        $landscape = in_array($payload['type'], ['ventas-clientes', 'responsable'], true);
        [$columns, $rows] = $this->table($payload['type'], $payload['data']);
        $firstCapacity = $landscape ? 20 : 34;
        $followingCapacity = $landscape ? 27 : 42;
        $chunks = [];
        $remaining = $rows;
        $chunks[] = array_splice($remaining, 0, $firstCapacity);
        while ($remaining !== []) {
            $chunks[] = array_splice($remaining, 0, $followingCapacity);
        }

        return array_map(
            fn (array $pageRows, int $index): string => $this->renderPage(
                $payload,
                $columns,
                $pageRows,
                $landscape,
                $index + 1,
                count($chunks),
            ),
            $chunks,
            array_keys($chunks),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{label: string, width: float, align?: string}>  $columns
     * @param  list<list<string>>  $rows
     */
    private function renderPage(
        array $payload,
        array $columns,
        array $rows,
        bool $landscape,
        int $page,
        int $pageCount,
    ): string {
        [$width, $height] = $landscape ? [1980, 1400] : [1400, 1980];
        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 23, 32, 42);
        $muted = imagecolorallocate($image, 96, 108, 101);
        $green = imagecolorallocate($image, 184, 216, 195);
        $pale = imagecolorallocate($image, 245, 248, 246);
        $line = imagecolorallocate($image, 205, 215, 208);
        imagefill($image, 0, 0, $white);

        $margin = 48;
        $cursorY = 50;
        if ($page === 1) {
            $company = $payload['company']->nombre_comercial ?: $payload['company']->razon_social;
            $this->centerText($image, $company, 15, $cursorY, $muted, false, $width);
            $cursorY += 48;
            $this->centerText($image, mb_strtoupper($payload['title']), 28, $cursorY, $ink, true, $width);
            $cursorY += 57;
            $period = 'Periodo: '.CarbonImmutable::parse($payload['from'])->format('d/m/Y')
                .' al '.CarbonImmutable::parse($payload['to'])->format('d/m/Y');
            $this->centerText($image, $period, 16, $cursorY, $muted, false, $width);
            $cursorY += 55;
            if (in_array($payload['type'], ['estado-cliente', 'estado-proveedor'], true)) {
                $label = $payload['type'] === 'estado-cliente' ? 'Cliente: ' : 'Proveedor: ';
                $this->centerText(
                    $image,
                    $label.$payload['data']['counterparty']->nombre_razon_social,
                    17,
                    $cursorY,
                    $ink,
                    true,
                    $width,
                );
                $cursorY += 42;
            }
            $cursorY = $this->drawSummary($image, $payload, $cursorY, $margin, $width, $ink, $muted, $pale, $line);
        } else {
            $this->centerText($image, mb_strtoupper($payload['title']).' - CONTINUACION', 20, $cursorY, $ink, true, $width);
            $cursorY += 55;
        }

        $tableWidth = $width - ($margin * 2);
        $headerHeight = 48;
        imagefilledrectangle($image, $margin, $cursorY, $margin + $tableWidth, $cursorY + $headerHeight, $green);
        $x = $margin;
        foreach ($columns as $column) {
            $columnWidth = (int) round($tableWidth * $column['width']);
            imagerectangle($image, $x, $cursorY, $x + $columnWidth, $cursorY + $headerHeight, $ink);
            $this->drawCellText($image, $column['label'], $x, $cursorY, $columnWidth, $headerHeight, 11, $ink, true, 'center');
            $x += $columnWidth;
        }
        $cursorY += $headerHeight;

        if ($rows === []) {
            $this->drawCellText($image, 'No hay registros en el periodo seleccionado.', $margin, $cursorY, $tableWidth, 80, 16, $muted, false, 'center');
            $cursorY += 80;
        } else {
            foreach ($rows as $rowIndex => $row) {
                $rowHeight = 42;
                if ($rowIndex % 2 === 1) {
                    imagefilledrectangle($image, $margin, $cursorY, $margin + $tableWidth, $cursorY + $rowHeight, $pale);
                }
                $x = $margin;
                foreach ($columns as $index => $column) {
                    $columnWidth = (int) round($tableWidth * $column['width']);
                    imageline($image, $x, $cursorY + $rowHeight, $x + $columnWidth, $cursorY + $rowHeight, $line);
                    $this->drawCellText(
                        $image,
                        $row[$index] ?? '-',
                        $x,
                        $cursorY,
                        $columnWidth,
                        $rowHeight,
                        11,
                        $ink,
                        false,
                        $column['align'] ?? 'left',
                    );
                    $x += $columnWidth;
                }
                $cursorY += $rowHeight;
            }
        }

        $generated = 'Generado: '.$payload['generatedAt']->format('d/m/Y H:i');
        imagettftext($image, 11, 0, $margin, $height - 34, $muted, $this->regularFont, $generated);
        $this->centerText($image, "Pagina {$page} de {$pageCount}", 13, $height - 36, $muted, false, $width);

        ob_start();
        imagepng($image, null, 7);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    /** @param array<string, mixed> $payload */
    private function drawSummary(
        GdImage $image,
        array $payload,
        int $y,
        int $margin,
        int $width,
        int $ink,
        int $muted,
        int $background,
        int $line,
    ): int {
        $items = $this->summary($payload['type'], $payload['data']);
        $gap = 10;
        $boxWidth = (int) (($width - ($margin * 2) - ($gap * (count($items) - 1))) / count($items));
        foreach ($items as $index => [$label, $value]) {
            $x = $margin + (($boxWidth + $gap) * $index);
            imagefilledrectangle($image, $x, $y, $x + $boxWidth, $y + 82, $background);
            imagerectangle($image, $x, $y, $x + $boxWidth, $y + 82, $line);
            $this->drawCellText($image, mb_strtoupper($label), $x, $y + 7, $boxWidth, 28, 10, $muted, false, 'center');
            $this->drawCellText($image, $value, $x, $y + 32, $boxWidth, 42, 18, $ink, true, 'center');
        }

        return $y + 106;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{0: string, 1: string}>
     */
    private function summary(string $type, array $data): array
    {
        return match ($type) {
            'ventas-clientes' => [
                ['Registros de venta', (string) $data['rows']->count()],
                ['Aves netas', number_format($data['totals']['birds'])],
                ['Peso neto', number_format($data['totals']['net_weight'], 3).' kg'],
                ['Venta total', 'S/ '.number_format($data['totals']['amount'], 2)],
            ],
            'estado-cliente', 'estado-proveedor' => [
                ['Saldo anterior', 'S/ '.number_format($data['opening'], 2)],
                ['Cargos', 'S/ '.number_format($data['charges'], 2)],
                ['Abonos', 'S/ '.number_format($data['credits'], 2)],
                ['Saldo final', 'S/ '.number_format($data['balance'], 2)],
            ],
            'responsable' => [
                ['Responsable', $data['user_name']],
                ['Ingresos', 'S/ '.number_format($data['income'], 2)],
                ['Egresos', 'S/ '.number_format($data['expense'], 2)],
                ['Diferencia', 'S/ '.number_format($data['income'] - $data['expense'], 2)],
            ],
            default => [
                ['Registros', (string) $data['rows']->count()],
                ['Ingresos', 'S/ '.number_format($data['income'], 2)],
                ['Egresos', 'S/ '.number_format($data['expense'], 2)],
                ['Importe listado', 'S/ '.number_format($data['total'], 2)],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<array{label: string, width: float, align?: string}>, 1: list<list<string>>}
     */
    private function table(string $type, array $data): array
    {
        if (in_array($type, ['estado-cliente', 'estado-proveedor'], true)) {
            $columns = [
                ['label' => 'Fecha', 'width' => .10], ['label' => 'Codigo', 'width' => .14],
                ['label' => 'Tipo', 'width' => .14], ['label' => 'Detalle', 'width' => .22],
                ['label' => 'Kg', 'width' => .08, 'align' => 'right'], ['label' => 'Precio', 'width' => .08, 'align' => 'right'],
                ['label' => 'Cargo', 'width' => .08, 'align' => 'right'], ['label' => 'Abono', 'width' => .08, 'align' => 'right'],
                ['label' => 'Saldo', 'width' => .08, 'align' => 'right'],
            ];
            $rows = $data['rows']->map(fn (array $row): array => [
                CarbonImmutable::parse($row['date'])->format('d/m/Y'), $row['code'], $row['type'], $row['detail'] ?: '-',
                $row['weight'] !== null ? number_format($row['weight'], 3) : '-',
                $row['price'] !== null ? number_format($row['price'], 2) : '-',
                $row['debit'] > 0 ? number_format($row['debit'], 2) : '-',
                $row['credit'] > 0 ? number_format($row['credit'], 2) : '-',
                number_format($row['balance'], 2),
            ])->all();

            return [$columns, $rows];
        }

        if ($type === 'ventas-clientes') {
            $columns = [
                ['label' => 'Cliente', 'width' => .16], ['label' => 'Fecha y hora', 'width' => .11],
                ['label' => 'Canal', 'width' => .07], ['label' => 'Producto', 'width' => .09],
                ['label' => 'Javas / band.', 'width' => .08, 'align' => 'right'], ['label' => 'Aves', 'width' => .06, 'align' => 'right'],
                ['label' => 'P. bruto', 'width' => .08, 'align' => 'right'], ['label' => 'Tara', 'width' => .06, 'align' => 'right'],
                ['label' => 'Devolucion', 'width' => .07, 'align' => 'right'], ['label' => 'P. neto', 'width' => .07, 'align' => 'right'],
                ['label' => 'Precio', 'width' => .06, 'align' => 'right'], ['label' => 'Total S/', 'width' => .09, 'align' => 'right'],
            ];
            $rows = $data['rows']->map(fn (array $row): array => [
                $row['customer'], CarbonImmutable::parse($row['date_time'])->format('d/m/Y H:i'), $row['channel'], $row['product'],
                number_format($row['containers']), number_format($row['birds']),
                number_format($row['gross_weight'], 3), number_format($row['tare'], 3), number_format($row['returns'], 3),
                number_format($row['net_weight'], 3), $row['net_weight'] != 0 ? number_format($row['amount'] / $row['net_weight'], 2) : '-',
                number_format($row['amount'], 2),
            ])->all();

            return [$columns, $rows];
        }

        $columns = [
            ['label' => 'Fecha', 'width' => .09], ['label' => 'Codigo', 'width' => .12],
            ['label' => 'Cliente / proveedor', 'width' => .20], ['label' => 'Tipo', 'width' => .14],
            ['label' => 'Metodo', 'width' => .10], ['label' => 'Detalle', 'width' => .19],
            ['label' => 'Responsable', 'width' => .10], ['label' => 'Monto', 'width' => .06, 'align' => 'right'],
        ];
        $rows = $data['rows']->map(fn (array $row): array => [
            $row['date']->format('d/m/Y'), $row['code'], $row['counterparty'], $row['type'],
            $row['method'], $row['detail'] ?: '-', $row['user'], number_format($row['amount'], 2),
        ])->all();

        return [$columns, $rows];
    }

    private function centerText(GdImage $image, string $text, int $size, int $baseline, int $color, bool $bold, int $width): void
    {
        $font = $bold ? $this->boldFont : $this->regularFont;
        $box = imagettfbbox($size, 0, $font, $text);
        $textWidth = abs($box[4] - $box[0]);
        imagettftext($image, $size, 0, (int) (($width - $textWidth) / 2), $baseline, $color, $font, $text);
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
        $baseline = $y + (int) (($height + $size) / 2) - 2;
        imagettftext($image, $size, 0, $textX, $baseline, $color, $font, $text);
    }

    private function fitText(string $text, string $font, int $size, int $maxWidth): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text));
        $text = $normalized === null || $normalized === '' ? '-' : $normalized;
        if ($this->textWidth($text, $font, $size) <= $maxWidth) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $this->textWidth($text.'...', $font, $size) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'...';
    }

    private function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return abs($box[4] - $box[0]);
    }
}
