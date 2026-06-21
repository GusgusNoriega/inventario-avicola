<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'tercero_id',
    'codigo',
    'nombre',
    'operacion',
    'estado',
    'created_by',
])]
class ListaPrecio extends Model
{
    public const OPERATION_PURCHASE = 'COMPRA';

    public const OPERATION_SALE = 'VENTA';

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'listas_precios';

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }

    /**
     * @return HasMany<PrecioHistorial, $this>
     */
    public function precios(): HasMany
    {
        return $this->hasMany(PrecioHistorial::class, 'lista_precio_id');
    }

    /**
     * @return HasMany<PrecioHistorial, $this>
     */
    public function preciosVigentes(): HasMany
    {
        return $this->precios()->whereNull('vigente_hasta');
    }
}
