<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['codigo', 'nombre', 'permite_despacho', 'precio_fuente_tipo_pollo_id', 'estado'])]
class TipoPollo extends Model
{
    public const CHICKEN_LIVE = 'POLLO_VIVO';

    public const CHICKEN_DEAD = 'POLLO_MUERTO';

    public const CHICKEN_DRESSED = 'POLLO_PELADO';

    public const CHICKEN_PROCESSED = 'POLLO_BENEFICIADO';

    public const STATUS_ACTIVE = 'ACTIVO';

    protected $table = 'tipos_pollo';

    public function priceSourceTypeId(): int
    {
        if (! $this->precio_fuente_tipo_pollo_id && $this->codigo === self::CHICKEN_DEAD) {
            return (int) self::query()
                ->where('codigo', self::CHICKEN_LIVE)
                ->value('id') ?: (int) $this->id;
        }

        return (int) ($this->precio_fuente_tipo_pollo_id ?: $this->id);
    }

    /**
     * @return HasMany<PrecioHistorial, $this>
     */
    public function precios(): HasMany
    {
        return $this->hasMany(PrecioHistorial::class);
    }

    /**
     * @return BelongsTo<TipoPollo, $this>
     */
    public function precioFuente(): BelongsTo
    {
        return $this->belongsTo(self::class, 'precio_fuente_tipo_pollo_id');
    }

    protected function casts(): array
    {
        return [
            'permite_despacho' => 'boolean',
            'precio_fuente_tipo_pollo_id' => 'integer',
        ];
    }
}
