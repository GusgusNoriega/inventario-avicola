<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use GdImage;
use RuntimeException;

class ReportImageRenderer
{
    private string $regularFont;

    private string $boldFont;

    public function __construct(
        private readonly ReportPaletteService $palettes,
    ) {
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
        $firstCapacity = match (true) {
            $landscape => 20,
            $payload['type'] === 'deuda-clientes' => 29,
            $payload['type'] === 'estado-cliente' => 37,
            default => 34,
        };
        $followingCapacity = $landscape ? 27 : ($payload['type'] === 'deuda-clientes' ? 37 : 42);
        $chunks = $this->chunkRows($rows, $firstCapacity, $followingCapacity);

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
     * @param  list<list<string>|array{kind: string, cells: list<string>, movement?: string, group_start?: bool}>  $rows
     * @return list<list<list<string>|array{kind: string, cells: list<string>, movement?: string, group_start?: bool}>>
     */
    private function chunkRows(array $rows, int $firstCapacity, int $followingCapacity): array
    {
        $chunks = [];
        $remaining = $rows;
        $capacity = $firstCapacity;

        do {
            $chunk = array_splice($remaining, 0, $capacity);
            $last = $chunk[array_key_last($chunk)] ?? null;
            if ($remaining !== [] && is_array($last) && ($last['kind'] ?? null) === 'day') {
                array_unshift($remaining, array_pop($chunk));
            }
            $next = $remaining[0] ?? null;
            if ($chunk !== [] && is_array($next) && ($next['kind'] ?? null) === 'customer_total') {
                array_unshift($remaining, array_pop($chunk));
            }
            $chunks[] = $chunk;
            $capacity = $followingCapacity;
        } while ($remaining !== []);

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{label: string, width: float, align?: string}>  $columns
     * @param  list<list<string>|array{kind: string, cells: list<string>, movement?: string, group_start?: bool}>  $rows
     */
    private function renderPage(
        array $payload,
        array $columns,
        array $rows,
        bool $landscape,
        int $page,
        int $pageCount,
    ): string {
        [$width, $height] = $landscape
            ? [1980, 1400]
            : ($payload['type'] === 'deuda-clientes' ? [1400, 1812] : [1400, 1980]);
        $image = imagecreatetruecolor($width, $height);
        $palette = $this->palettes->normalize(
            is_array($payload['reportPalette'] ?? null) ? $payload['reportPalette'] : null,
        );
        $pageBackground = $this->allocateColor($image, $palette['page_background']);
        $primary = $this->allocateColor($image, $palette['primary']);
        $secondary = $this->allocateColor($image, $palette['secondary']);
        $secondaryText = $this->allocateColor($image, $palette['secondary_text']);
        $accent = $this->allocateColor($image, $palette['accent']);
        $ink = $this->allocateColor($image, $palette['body_text']);
        $muted = $this->allocateColor($image, $palette['muted_text']);
        $line = $this->allocateColor($image, $palette['border']);
        $blue = $this->allocateColor($image, $palette['debit']);
        $red = $this->allocateColor($image, $palette['credit']);
        imagefill($image, 0, 0, $pageBackground);

        $margin = 48;
        $cursorY = 50;
        if ($page === 1) {
            $company = $payload['company']->nombre_comercial ?: $payload['company']->razon_social;
            $this->centerText($image, $company, 15, $cursorY, $muted, false, $width);
            $cursorY += 48;
            $this->centerText($image, mb_strtoupper($payload['title']), 28, $cursorY, $primary, true, $width);
            $cursorY += 57;
            $period = 'Periodo: '.CarbonImmutable::parse($payload['from'])->format('d/m/Y')
                .' al '.CarbonImmutable::parse($payload['to'])->format('d/m/Y');
            $this->centerText($image, $period, 16, $cursorY, $muted, false, $width);
            $cursorY += 55;
            if ($payload['type'] === 'deuda-clientes') {
                $updated = 'Actualizado: '.$payload['generatedAt']->format('d/m/Y H:i')
                    .' - Moneda: '.$payload['data']['currency'];
                $this->centerText($image, $updated, 14, $cursorY, $muted, false, $width);
                $cursorY += 42;
            }
            if (in_array($payload['type'], ['estado-cliente', 'estado-proveedor'], true)) {
                $label = $payload['type'] === 'estado-cliente' ? 'Cliente: ' : 'Proveedor: ';
                $this->centerText(
                    $image,
                    $label.$payload['data']['counterparty']->nombre_razon_social,
                    17,
                    $cursorY,
                    $primary,
                    true,
                    $width,
                );
                $cursorY += 42;
            }
            if ($payload['type'] === 'estado-cliente') {
                $cursorY += 10;
            } else {
                $cursorY = $this->drawSummary($image, $payload, $cursorY, $margin, $width, $primary, $muted, $accent, $line);
            }
        } else {
            $this->centerText($image, mb_strtoupper($payload['title']).' - CONTINUACION', 20, $cursorY, $primary, true, $width);
            $cursorY += 55;
        }

        $tableWidth = $width - ($margin * 2);
        $headerHeight = 48;
        imagefilledrectangle($image, $margin, $cursorY, $margin + $tableWidth, $cursorY + $headerHeight, $secondary);
        $x = $margin;
        foreach ($columns as $column) {
            $columnWidth = (int) round($tableWidth * $column['width']);
            imagerectangle($image, $x, $cursorY, $x + $columnWidth, $cursorY + $headerHeight, $primary);
            $this->drawCellText($image, $column['label'], $x, $cursorY, $columnWidth, $headerHeight, 11, $secondaryText, true, 'center');
            $x += $columnWidth;
        }
        $cursorY += $headerHeight;

        if ($rows === []) {
            $this->drawCellText($image, 'No hay registros en el periodo seleccionado.', $margin, $cursorY, $tableWidth, 80, 16, $muted, false, 'center');
            $cursorY += 80;
        } else {
            $dataRowIndex = 0;
            foreach ($rows as $row) {
                $kind = is_string($row['kind'] ?? null) ? $row['kind'] : 'data';
                $cells = isset($row['cells']) && is_array($row['cells']) ? $row['cells'] : $row;

                if ($kind === 'day') {
                    $rowHeight = 42;
                    imagefilledrectangle($image, $margin, $cursorY, $margin + $tableWidth, $cursorY + $rowHeight, $accent);
                    imagerectangle($image, $margin, $cursorY, $margin + $tableWidth, $cursorY + $rowHeight, $line);
                    $this->drawCellText(
                        $image,
                        $cells[0] ?? '',
                        $margin,
                        $cursorY,
                        $tableWidth,
                        $rowHeight,
                        11,
                        $primary,
                        true,
                        'left',
                    );
                    $cursorY += $rowHeight;

                    continue;
                }

                if ($kind === 'opening') {
                    $rowHeight = 42;
                    $balanceWidth = (int) round($tableWidth * $columns[array_key_last($columns)]['width']);
                    $labelWidth = $tableWidth - $balanceWidth;
                    imageline($image, $margin, $cursorY + $rowHeight, $margin + $tableWidth, $cursorY + $rowHeight, $line);
                    $this->drawCellText(
                        $image,
                        $cells[0] ?? '',
                        $margin,
                        $cursorY,
                        $labelWidth,
                        $rowHeight,
                        11,
                        $muted,
                        false,
                        'left',
                    );
                    $this->drawCellText(
                        $image,
                        $cells[array_key_last($cells)] ?? '-',
                        $margin + $labelWidth,
                        $cursorY,
                        $balanceWidth,
                        $rowHeight,
                        11,
                        $primary,
                        true,
                        'right',
                    );
                    $cursorY += $rowHeight;

                    continue;
                }

                if ($kind === 'empty') {
                    $rowHeight = 80;
                    $this->drawCellText(
                        $image,
                        $cells[0] ?? 'No hay registros en el periodo seleccionado.',
                        $margin,
                        $cursorY,
                        $tableWidth,
                        $rowHeight,
                        16,
                        $muted,
                        false,
                        'center',
                    );
                    $cursorY += $rowHeight;

                    continue;
                }

                if ($kind === 'customer_total') {
                    $rowHeight = 42;
                    imagefilledrectangle($image, $margin, $cursorY, $margin + $tableWidth, $cursorY + $rowHeight, $accent);
                    imageline($image, $margin, $cursorY, $margin + $tableWidth, $cursorY, $primary);
                    imageline($image, $margin, $cursorY + $rowHeight, $margin + $tableWidth, $cursorY + $rowHeight, $primary);
                    $labelWidth = (int) round($tableWidth * array_sum(array_column(array_slice($columns, 0, 4), 'width')));
                    $this->drawCellText(
                        $image,
                        mb_strtoupper($cells[0] ?? ''),
                        $margin,
                        $cursorY,
                        $labelWidth,
                        $rowHeight,
                        11,
                        $primary,
                        true,
                        'left',
                    );
                    $x = $margin + $labelWidth;
                    imageline($image, $x, $cursorY, $x, $cursorY + $rowHeight, $line);
                    foreach (array_slice($columns, 4, null, true) as $index => $column) {
                        $columnWidth = (int) round($tableWidth * $column['width']);
                        $this->drawCellText(
                            $image,
                            $cells[$index] ?? '-',
                            $x,
                            $cursorY,
                            $columnWidth,
                            $rowHeight,
                            11,
                            $primary,
                            true,
                            $column['align'] ?? 'left',
                        );
                        $x += $columnWidth;
                        imageline($image, $x, $cursorY, $x, $cursorY + $rowHeight, $line);
                    }
                    $cursorY += $rowHeight;

                    continue;
                }

                $rowHeight = 42;
                if ($dataRowIndex % 2 === 1) {
                    imagefilledrectangle($image, $margin, $cursorY, $margin + $tableWidth, $cursorY + $rowHeight, $accent);
                }
                if (($row['group_start'] ?? false) === true) {
                    imageline($image, $margin, $cursorY, $margin + $tableWidth, $cursorY, $primary);
                }
                $x = $margin;
                foreach ($columns as $index => $column) {
                    $columnWidth = (int) round($tableWidth * $column['width']);
                    imageline($image, $x, $cursorY + $rowHeight, $x + $columnWidth, $cursorY + $rowHeight, $line);
                    $movement = is_string($row['movement'] ?? null) ? $row['movement'] : null;
                    $cellColor = match (true) {
                        $payload['type'] === 'estado-cliente' && $index === 6 && $movement === 'debit' => $blue,
                        $payload['type'] === 'estado-cliente' && $index === 6 && $movement === 'credit' => $red,
                        $payload['type'] === 'ventas-clientes' && $index === 8 => $red,
                        $payload['type'] === 'ventas-clientes' && $index === 11 => $primary,
                        default => $ink,
                    };
                    $this->drawCellText(
                        $image,
                        $cells[$index] ?? '-',
                        $x,
                        $cursorY,
                        $columnWidth,
                        $rowHeight,
                        11,
                        $cellColor,
                        ($payload['type'] === 'estado-cliente'
                            && $index === 6
                            && in_array($movement, ['debit', 'credit'], true))
                            || ($payload['type'] === 'ventas-clientes' && in_array($index, [8, 11], true)),
                        $column['align'] ?? 'left',
                    );
                    $x += $columnWidth;
                }
                $cursorY += $rowHeight;
                $dataRowIndex++;
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
        int $primary,
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
            $this->drawCellText($image, $value, $x, $y + 32, $boxWidth, 42, 18, $primary, true, 'center');
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
            'deuda-clientes' => [
                ['Deuda anterior', number_format((float) $data['totals']['opening'], 2)],
                ['Deuda del periodo', number_format((float) $data['totals']['period_debt'], 2)],
                ['Total hasta el corte', number_format((float) $data['totals']['debt_to_date'], 2)],
                ['Pagos realizados', number_format((float) $data['totals']['payments'], 2)],
                ['Deuda actual', number_format((float) $data['totals']['balance'], 2)],
            ],
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
     * @return array{
     *     0: list<array{label: string, width: float, align?: string}>,
     *     1: list<list<string>|array{kind: string, cells: list<string>, movement?: string}>
     * }
     */
    private function table(string $type, array $data): array
    {
        if ($type === 'deuda-clientes') {
            $columns = [
                ['label' => 'Cliente', 'width' => .35],
                ['label' => 'Deuda anterior', 'width' => .13, 'align' => 'right'],
                ['label' => 'Deuda periodo', 'width' => .13, 'align' => 'right'],
                ['label' => 'Total al corte', 'width' => .13, 'align' => 'right'],
                ['label' => 'Pagos', 'width' => .13, 'align' => 'right'],
                ['label' => 'Deuda actual', 'width' => .13, 'align' => 'right'],
            ];
            $amount = static fn (mixed $value): string => (float) $value !== 0.0
                ? number_format((float) $value, 2)
                : '';
            $rows = $data['rows']->map(fn (array $row): array => [
                $row['customer'],
                $amount($row['opening']),
                $amount($row['period_debt']),
                $amount($row['debt_to_date']),
                $amount($row['payments']),
                $amount($row['balance']),
            ])->all();

            return [$columns, $rows];
        }

        if ($type === 'estado-cliente') {
            $columns = [
                ['label' => 'FEC.', 'width' => .10], ['label' => 'CÓD.', 'width' => .15],
                ['label' => 'TIPO', 'width' => .14], ['label' => 'DET.', 'width' => .27],
                ['label' => 'KG', 'width' => .08, 'align' => 'right'], ['label' => 'P/KG', 'width' => .07, 'align' => 'right'],
                ['label' => 'C/A', 'width' => .09, 'align' => 'right'],
                ['label' => 'Saldo', 'width' => .10, 'align' => 'right'],
            ];
            $rows = [[
                'kind' => 'opening',
                'cells' => [
                    'Saldo anterior',
                    '', '', '', '', '', '',
                    number_format($data['opening'], 2),
                ],
            ]];

            if ($data['rows']->isEmpty()) {
                $rows[] = [
                    'kind' => 'empty',
                    'cells' => ['No hay movimientos en el periodo seleccionado.'],
                ];
            } else {
                foreach ($data['rows']->groupBy('date') as $date => $dayRows) {
                    $rows[] = [
                        'kind' => 'day',
                        'cells' => ['Movimientos del '.CarbonImmutable::parse($date)->format('d/m/Y')],
                    ];
                    foreach ($dayRows as $row) {
                        $rows[] = [
                            'kind' => 'data',
                            'movement' => $row['effect'] > 0 ? 'debit' : ($row['effect'] < 0 ? 'credit' : 'neutral'),
                            'cells' => [
                                CarbonImmutable::parse($row['date'])->format('d/m/Y'),
                                $row['code'],
                                $row['type'],
                                $row['detail'] ?: '-',
                                $row['weight'] !== null ? number_format($row['weight'], 3) : '-',
                                $row['price'] !== null ? number_format($row['price'], 2) : '-',
                                $row['effect'] != 0 ? number_format(abs($row['effect']), 2) : '-',
                                number_format($row['balance'], 2),
                            ],
                        ];
                    }
                }
            }

            return [$columns, $rows];
        }

        if ($type === 'estado-proveedor') {
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
            $rows = [];
            foreach ($data['customer_groups'] as $group) {
                foreach ($group['rows'] as $index => $row) {
                    $rows[] = [
                        'kind' => 'data',
                        'group_start' => $index === 0,
                        'cells' => [
                            $row['customer'], CarbonImmutable::parse($row['date_time'])->format('d/m/Y H:i'), $row['channel'], $row['product'],
                            number_format($row['containers']), number_format($row['birds']),
                            number_format($row['gross_weight'], 3), number_format($row['tare'], 3), number_format($row['returns'], 3),
                            number_format($row['net_weight'], 3), $row['net_weight'] != 0 ? number_format($row['amount'] / $row['net_weight'], 2) : '-',
                            number_format($row['amount'], 2),
                        ],
                    ];
                }
                if ($group['rows']->count() > 1) {
                    $subtotal = $group['subtotal'];
                    $rows[] = [
                        'kind' => 'customer_total',
                        'cells' => [
                            'Total '.$group['customer'], '', '', '',
                            number_format($subtotal['containers']), number_format($subtotal['birds']),
                            number_format($subtotal['gross_weight'], 3), number_format($subtotal['tare'], 3), number_format($subtotal['returns'], 3),
                            number_format($subtotal['net_weight'], 3), $subtotal['weighted_price'] !== null ? number_format($subtotal['weighted_price'], 2) : '-',
                            number_format($subtotal['amount'], 2),
                        ],
                    ];
                }
            }

            return [$columns, $rows];
        }

        $columns = [
            ['label' => 'Fecha', 'width' => .09], ['label' => 'Codigo', 'width' => .12],
            ['label' => 'Contraparte', 'width' => .20], ['label' => 'Tipo', 'width' => .14],
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
        if (trim($text) === '') {
            return;
        }

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

    private function allocateColor(GdImage $image, string $hex): int
    {
        [$red, $green, $blue] = $this->palettes->rgb($hex);
        $color = imagecolorallocate($image, $red, $green, $blue);

        if ($color === false) {
            throw new RuntimeException('No se pudo preparar la paleta de la imagen.');
        }

        return $color;
    }
}
