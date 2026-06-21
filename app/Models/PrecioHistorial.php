<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lista_precio_id',
    'tipo_pollo_id',
    'precio_kg',
    'vigente_desde',
    'vigente_hasta',
    'motivo_cambio',
    'reemplaza_precio_id',
    'registrado_por',
])]
class PrecioHistorial extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'precios_historial';

    /**
     * @return BelongsTo<ListaPrecio, $this>
     */
    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class);
    }

    /**
     * @return BelongsTo<TipoPollo, $this>
     */
    public function tipoPollo(): BelongsTo
    {
        return $this->belongsTo(TipoPollo::class);
    }

    protected function casts(): array
    {
        return [
            'precio_kg' => 'decimal:4',
            'vigente_desde' => 'datetime',
            'vigente_hasta' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
