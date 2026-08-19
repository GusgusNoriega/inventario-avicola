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

    public const HEN_RED = 'GALLINA_ROJA';

    public const HEN_DOUBLE = 'GALLINA_DOBLE';

    public const OTHER = 'OTROS';

    public const STATUS_ACTIVE = 'ACTIVO';

    protected $table = 'tipos_pollo';

    /** @return list<string> */
    public static function wholesaleTwoManualPriceCodes(): array
    {
        return [self::HEN_RED, self::HEN_DOUBLE, self::OTHER];
    }

    /** @return list<string> */
    public static function wholesaleTwoClientPriceCodes(): array
    {
        return [self::HEN_RED, self::HEN_DOUBLE];
    }

    public static function requiresWholesaleTwoManualPrice(?string $code): bool
    {
        return in_array($code, self::wholesaleTwoManualPriceCodes(), true);
    }

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
     * @return HasMany<CompraDetalle, $this>
     */
    public function detallesCompra(): HasMany
    {
        return $this->hasMany(CompraDetalle::class);
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
