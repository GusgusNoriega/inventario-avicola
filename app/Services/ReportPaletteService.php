<?php

namespace App\Services;

use App\Models\Empresa;
use InvalidArgumentException;

class ReportPaletteService
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'page_background' => '#FFFFFF',
        'primary' => '#173C25',
        'primary_text' => '#FFFFFF',
        'secondary' => '#B8D8C3',
        'secondary_text' => '#14261B',
        'accent' => '#E7F1EA',
        'body_text' => '#17202A',
        'muted_text' => '#59636E',
        'border' => '#CCD4CF',
        'debit' => '#175CD3',
        'credit' => '#B42318',
    ];

    /** @var array<string, array{label: string, description: string}> */
    private const FIELDS = [
        'page_background' => [
            'label' => 'Fondo de página',
            'description' => 'Color de fondo general de los reportes.',
        ],
        'primary' => [
            'label' => 'Color principal',
            'description' => 'Encabezados principales y títulos destacados.',
        ],
        'primary_text' => [
            'label' => 'Texto sobre color principal',
            'description' => 'Texto mostrado sobre encabezados de color principal.',
        ],
        'secondary' => [
            'label' => 'Color secundario',
            'description' => 'Encabezados secundarios y agrupaciones de datos.',
        ],
        'secondary_text' => [
            'label' => 'Texto sobre color secundario',
            'description' => 'Texto mostrado sobre fondos de color secundario.',
        ],
        'accent' => [
            'label' => 'Fondo de énfasis',
            'description' => 'Fondos suaves para resúmenes y datos destacados.',
        ],
        'body_text' => [
            'label' => 'Texto principal',
            'description' => 'Texto general y valores de los reportes.',
        ],
        'muted_text' => [
            'label' => 'Texto secundario',
            'description' => 'Notas, fechas, encabezados de página y paginación.',
        ],
        'border' => [
            'label' => 'Bordes',
            'description' => 'Líneas divisorias, tablas y contornos.',
        ],
        'debit' => [
            'label' => 'Débitos',
            'description' => 'Movimientos y valores identificados como débitos.',
        ],
        'credit' => [
            'label' => 'Créditos',
            'description' => 'Movimientos y valores identificados como créditos.',
        ],
    ];

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /** @return array<string, array{label: string, description: string}> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /** @return array<string, string> */
    public function normalize(?array $palette): array
    {
        $normalized = self::DEFAULTS;

        if ($palette === null) {
            return $normalized;
        }

        foreach (array_keys(self::DEFAULTS) as $field) {
            $value = $palette[$field] ?? null;
            if (! is_string($value)) {
                continue;
            }

            $value = strtoupper(trim($value));
            if (preg_match('/\A#[0-9A-F]{6}\z/', $value) === 1) {
                $normalized[$field] = $value;
            }
        }

        return $normalized;
    }

    /** @return array<string, string> */
    public function current(Empresa $company): array
    {
        return $this->normalize(
            is_array($company->paleta_reportes) ? $company->paleta_reportes : null,
        );
    }

    /**
     * @param  array<string, mixed>  $palette
     * @return array<string, string>
     */
    public function save(Empresa $company, array $palette): array
    {
        $normalized = $this->normalize($palette);

        $company->update([
            'paleta_reportes' => $normalized,
        ]);

        return $normalized;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public function rgb(string $color): array
    {
        $color = strtoupper(trim($color));
        if (preg_match('/\A#[0-9A-F]{6}\z/', $color) !== 1) {
            throw new InvalidArgumentException('El color debe usar el formato hexadecimal #RRGGBB.');
        }

        return [
            hexdec(substr($color, 1, 2)),
            hexdec(substr($color, 3, 2)),
            hexdec(substr($color, 5, 2)),
        ];
    }

    /** @return array{0: float, 1: float, 2: float} */
    public function dompdfColor(string $color): array
    {
        return array_map(
            static fn (int $channel): float => $channel / 255,
            $this->rgb($color),
        );
    }

    public function contrastRatio(string $first, string $second): float
    {
        $firstLuminance = $this->relativeLuminance($first);
        $secondLuminance = $this->relativeLuminance($second);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $color): float
    {
        $channels = array_map(
            static function (int $channel): float {
                $value = $channel / 255;

                return $value <= 0.04045
                    ? $value / 12.92
                    : (($value + 0.055) / 1.055) ** 2.4;
            },
            $this->rgb($color),
        );

        return (0.2126 * $channels[0])
            + (0.7152 * $channels[1])
            + (0.0722 * $channels[2]);
    }
}
