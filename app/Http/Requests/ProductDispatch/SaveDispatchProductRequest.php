<?php

namespace App\Http\Requests\ProductDispatch;

use App\Models\ProductoDespacho;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDispatchProductRequest extends FormRequest
{
    public const MAX_VARIATIONS = 19;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:2', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'modo_precio' => ['required', Rule::in([
                ProductoDespacho::PRICE_MODE_KG,
                ProductoDespacho::PRICE_MODE_UNIT,
            ])],
            'precio_venta' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999.9999'],
            'merma_gramos_unidad' => ['required', 'integer', 'min:0', 'max:1000000'],
            'imagen' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'eliminar_imagen' => ['sometimes', 'boolean'],
            'sincronizar_variaciones' => ['sometimes', 'boolean'],
            'estado' => ['sometimes', Rule::in([
                ProductoDespacho::STATUS_ACTIVE,
                ProductoDespacho::STATUS_INACTIVE,
            ])],
            'variaciones' => ['sometimes', 'array', 'max:'.self::MAX_VARIATIONS],
            'variaciones.*' => ['array'],
            'variaciones.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'variaciones.*.nombre' => ['required', 'string', 'min:1', 'max:120'],
            'variaciones.*.modo_precio' => ['required', Rule::in([
                ProductoDespacho::PRICE_MODE_KG,
                ProductoDespacho::PRICE_MODE_UNIT,
            ])],
            'variaciones.*.precio_venta' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999.9999'],
            'variaciones.*.merma_gramos_unidad' => ['required', 'integer', 'min:0', 'max:1000000'],
            'variaciones.*.imagen' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'variaciones.*.eliminar_imagen' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre del producto.',
            'modo_precio.required' => 'Indica si el producto se cobra por kilogramo o por unidad.',
            'precio_venta.required' => 'Indica el precio de venta del producto.',
            'precio_venta.gt' => 'El precio del producto debe ser mayor que cero.',
            'precio_venta.decimal' => 'El precio puede tener hasta cuatro decimales.',
            'merma_gramos_unidad.required' => 'Indica la merma del producto; usa cero si no aplica.',
            'imagen.image' => 'La imagen destacada debe ser un archivo de imagen válido.',
            'imagen.mimes' => 'La imagen debe estar en formato JPG, PNG o WEBP.',
            'imagen.max' => 'La imagen no puede pesar más de 4 MB.',
            'variaciones.max' => 'Un producto puede tener hasta '.self::MAX_VARIATIONS.' variaciones.',
            'variaciones.*.array' => 'Cada variación debe tener nombre, precio y merma.',
            'variaciones.*.nombre.required' => 'Escribe el nombre de cada variación.',
            'variaciones.*.modo_precio.required' => 'Indica cómo se cobra cada variación.',
            'variaciones.*.precio_venta.required' => 'Indica el precio de cada variación.',
            'variaciones.*.precio_venta.gt' => 'El precio de cada variación debe ser mayor que cero.',
            'variaciones.*.precio_venta.decimal' => 'El precio de cada variación puede tener hasta cuatro decimales.',
            'variaciones.*.merma_gramos_unidad.required' => 'Indica la merma de cada variación; usa cero si no aplica.',
            'variaciones.*.imagen.image' => 'Cada imagen de variación debe ser un archivo válido.',
            'variaciones.*.imagen.mimes' => 'Las imágenes de variaciones deben ser JPG, PNG o WEBP.',
            'variaciones.*.imagen.max' => 'Cada imagen de variación puede pesar hasta 4 MB.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $names = collect($this->input('variaciones', []))
                ->map(function (mixed $variation): ?string {
                    if (! is_array($variation) || ! is_string($variation['nombre'] ?? null)) {
                        return null;
                    }

                    return self::normalizeName($variation['nombre']);
                })
                ->filter();

            if ($names->duplicates()->isNotEmpty()) {
                $validator->errors()->add(
                    'variaciones',
                    'No puedes repetir el mismo nombre de variación dentro de un producto.',
                );
            }
        }];
    }

    public static function normalizeName(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)), 'UTF-8');
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();
        $data['nombre'] = $this->cleanText($data['nombre'] ?? null);
        $data['descripcion'] = $this->cleanText($data['descripcion'] ?? null);

        if (isset($data['variaciones']) && is_array($data['variaciones'])) {
            $data['variaciones'] = collect($data['variaciones'])
                ->map(function (mixed $variation): mixed {
                    if (! is_array($variation)) {
                        return $variation;
                    }

                    $variation['nombre'] = $this->cleanText($variation['nombre'] ?? null);

                    return $variation;
                })
                ->values()
                ->all();
        }

        $this->replace($data);
    }

    private function cleanText(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $cleaned = (string) preg_replace('/\s+/u', ' ', trim($value));

        return $cleaned === '' ? null : $cleaned;
    }
}
