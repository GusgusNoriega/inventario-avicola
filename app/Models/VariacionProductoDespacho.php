<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'producto_despacho_id',
    'nombre',
    'nombre_normalizado',
    'modo_precio',
    'precio_venta',
    'merma_gramos_unidad',
    'imagen_path',
    'orden',
    'estado',
    'created_by',
    'updated_by',
])]
class VariacionProductoDespacho extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'variaciones_producto_despacho';

    /** @return BelongsTo<ProductoDespacho, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoDespacho::class, 'producto_despacho_id');
    }

    /** @return HasMany<PesadaDespachoProducto, $this> */
    public function pesadasDespacho(): HasMany
    {
        return $this->hasMany(PesadaDespachoProducto::class, 'variacion_producto_despacho_id');
    }

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:4',
            'merma_gramos_unidad' => 'integer',
            'orden' => 'integer',
        ];
    }
}
