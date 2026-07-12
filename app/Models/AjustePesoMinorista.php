<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'codigo',
    'nombre',
    'sexo',
    'presentacion',
    'gramos_adicionales',
    'predeterminado',
    'estado',
])]
class AjustePesoMinorista extends Model
{
    public const MALE_CLOSED = 'MACHO_CERRADO';

    public const MALE_OPEN = 'MACHO_ABIERTO';

    public const FEMALE_CLOSED = 'HEMBRA_CERRADA';

    public const FEMALE_OPEN = 'HEMBRA_ABIERTA';

    public const STATUS_ACTIVE = 'ACTIVO';

    protected $table = 'ajustes_peso_minorista';

    /** @return list<string> */
    public static function codes(): array
    {
        return [
            self::MALE_CLOSED,
            self::MALE_OPEN,
            self::FEMALE_CLOSED,
            self::FEMALE_OPEN,
        ];
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return HasMany<Pesada, $this>
     */
    public function pesadas(): HasMany
    {
        return $this->hasMany(Pesada::class, 'ajuste_peso_minorista_id');
    }

    protected function casts(): array
    {
        return [
            'gramos_adicionales' => 'integer',
            'predeterminado' => 'boolean',
        ];
    }
}
