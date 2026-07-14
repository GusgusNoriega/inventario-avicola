<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'compra_id',
    'tipo_pollo_id',
    'descripcion',
    'cantidad_aves',
    'peso_kg',
    'precio_kg',
    'subtotal',
])]
class CompraDetalle extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'compra_detalles';

    /** @return BelongsTo<Compra, $this> */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    /** @return BelongsTo<TipoPollo, $this> */
    public function tipoPollo(): BelongsTo
    {
        return $this->belongsTo(TipoPollo::class);
    }

    protected function casts(): array
    {
        return [
            'cantidad_aves' => 'integer',
            'peso_kg' => 'decimal:3',
            'precio_kg' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
