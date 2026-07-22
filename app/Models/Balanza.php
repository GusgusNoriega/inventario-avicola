<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sucursal_id',
    'codigo',
    'nombre',
    'modo_conexion',
    'dispositivo',
    'configuracion',
    'estado',
])]
class Balanza extends Model
{
    public const CODE_WHOLESALE_1 = 'BALANZA_1';

    public const CODE_WHOLESALE_2 = 'BALANZA_2';

    public const CODE_RETAIL_1 = 'BALANZA_MINORISTA';

    public const CODE_RETAIL_2 = 'BALANZA_MINORISTA_2';

    public const STATUS_ACTIVE = 'ACTIVO';

    protected $table = 'balanzas';

    public static function logicalName(string $code): ?string
    {
        return match ($code) {
            self::CODE_WHOLESALE_1 => 'Balanza 1',
            self::CODE_WHOLESALE_2 => 'Balanza 2',
            self::CODE_RETAIL_1 => 'Balanza despacho minorista',
            self::CODE_RETAIL_2 => 'Balanza despacho minorista 2',
            default => null,
        };
    }

    /** @return BelongsTo<Sucursal, $this> */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** @return HasMany<LecturaBalanza, $this> */
    public function lecturas(): HasMany
    {
        return $this->hasMany(LecturaBalanza::class);
    }

    protected function casts(): array
    {
        return [
            'configuracion' => 'array',
        ];
    }
}
