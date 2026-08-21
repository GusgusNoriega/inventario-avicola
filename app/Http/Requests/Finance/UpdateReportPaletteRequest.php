<?php

namespace App\Http\Requests\Finance;

use App\Services\ReportPaletteService;
use Illuminate\Validation\Validator;

class UpdateReportPaletteRequest extends FinancialFormRequest
{
    private const MINIMUM_CONTRAST_RATIO = 4.5;

    /**
     * @var list<array{background: string, foreground: string, message: string}>
     */
    private const CONTRAST_PAIRS = [
        [
            'background' => 'page_background',
            'foreground' => 'primary',
            'message' => 'El color principal debe distinguirse claramente del fondo de página.',
        ],
        [
            'background' => 'primary',
            'foreground' => 'primary_text',
            'message' => 'El texto principal debe distinguirse claramente del color principal.',
        ],
        [
            'background' => 'secondary',
            'foreground' => 'secondary_text',
            'message' => 'El texto secundario debe distinguirse claramente del color secundario.',
        ],
        [
            'background' => 'accent',
            'foreground' => 'body_text',
            'message' => 'El texto general debe distinguirse claramente del fondo de énfasis.',
        ],
        [
            'background' => 'accent',
            'foreground' => 'primary',
            'message' => 'El color principal debe distinguirse claramente del fondo de énfasis.',
        ],
        [
            'background' => 'accent',
            'foreground' => 'muted_text',
            'message' => 'El texto secundario debe distinguirse claramente del fondo de énfasis.',
        ],
        [
            'background' => 'accent',
            'foreground' => 'debit',
            'message' => 'El color de los débitos debe distinguirse claramente del fondo de énfasis.',
        ],
        [
            'background' => 'accent',
            'foreground' => 'credit',
            'message' => 'El color de los créditos debe distinguirse claramente del fondo de énfasis.',
        ],
        [
            'background' => 'page_background',
            'foreground' => 'body_text',
            'message' => 'El texto general debe distinguirse claramente del fondo de página.',
        ],
        [
            'background' => 'page_background',
            'foreground' => 'muted_text',
            'message' => 'El texto secundario debe distinguirse claramente del fondo de página.',
        ],
        [
            'background' => 'page_background',
            'foreground' => 'debit',
            'message' => 'El color de los débitos debe distinguirse claramente del fondo de página.',
        ],
        [
            'background' => 'page_background',
            'foreground' => 'credit',
            'message' => 'El color de los créditos debe distinguirse claramente del fondo de página.',
        ],
    ];

    /** @var string */
    protected $errorBag = 'reportPalette';

    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $fields = array_keys((new ReportPaletteService)->defaults());
        $rules = [
            'colors' => [
                'required',
                'array:'.implode(',', $fields),
                'required_array_keys:'.implode(',', $fields),
            ],
        ];

        foreach ($fields as $field) {
            $rules['colors.'.$field] = [
                'required',
                'string',
                'regex:/\A#[0-9A-F]{6}\z/',
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $colors = $this->input('colors');
        if (! is_array($colors)) {
            return;
        }

        foreach ($colors as $field => $color) {
            if (is_string($color)) {
                $colors[$field] = strtoupper(trim($color));
            }
        }

        $this->merge(['colors' => $colors]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $service = app(ReportPaletteService::class);
            $fields = array_keys($service->defaults());
            $colors = $this->input('colors');

            if (! is_array($colors)
                || array_diff($fields, array_keys($colors)) !== []
                || array_diff(array_keys($colors), $fields) !== []) {
                return;
            }

            foreach ($fields as $field) {
                if (! is_string($colors[$field] ?? null)
                    || preg_match('/\A#[0-9A-F]{6}\z/', $colors[$field]) !== 1) {
                    return;
                }
            }

            foreach (self::CONTRAST_PAIRS as $pair) {
                if ($service->contrastRatio(
                    $colors[$pair['background']],
                    $colors[$pair['foreground']],
                ) < self::MINIMUM_CONTRAST_RATIO) {
                    $validator->errors()->add(
                        'colors.'.$pair['foreground'],
                        $pair['message'].' Usa una relación de contraste mínima de 4.5:1.',
                    );
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'colors.required' => 'Envía la paleta completa de colores.',
            'colors.array' => 'La paleta contiene colores adicionales o tiene un formato inválido.',
            'colors.required_array_keys' => 'Envía exactamente todos los colores de la paleta.',
            'colors.*.required' => 'Todos los colores de la paleta son obligatorios.',
            'colors.*.string' => 'Cada color debe ser un texto hexadecimal.',
            'colors.*.regex' => 'Cada color debe usar exactamente el formato #RRGGBB.',
        ];
    }
}
