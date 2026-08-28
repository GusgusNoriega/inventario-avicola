<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'nombre',
    'nombre_normalizado',
    'descripcion',
    'modo_precio',
    'precio_venta',
    'merma_gramos_unidad',
    'imagen_path',
    'estado',
    'created_by',
    'updated_by',
])]
class ProductoDespacho extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    public const PRICE_MODE_KG = 'POR_KG';

    public const PRICE_MODE_UNIT = 'POR_UNIDAD';

    protected $table = 'productos_despacho';

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<VariacionProductoDespacho, $this> */
    public function variaciones(): HasMany
    {
        return $this->hasMany(VariacionProductoDespacho::class)
            ->orderBy('orden')
            ->orderBy('nombre');
    }

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:4',
            'merma_gramos_unidad' => 'integer',
        ];
    }
}
