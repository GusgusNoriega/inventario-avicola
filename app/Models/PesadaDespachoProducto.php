<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ticket_despacho_producto_id',
    'numero',
    'producto_despacho_id',
    'variacion_producto_despacho_id',
    'lectura_balanza_id',
    'producto_nombre_snapshot',
    'variacion_nombre_snapshot',
    'modo_precio_snapshot',
    'precio_catalogo_snapshot',
    'precio_venta_snapshot',
    'origen_precio',
    'cantidad',
    'origen_peso',
    'peso_leido_kg',
    'merma_catalogo_gramos_unidad',
    'merma_aplicada_gramos_unidad',
    'merma_total_gramos',
    'tara_gramos',
    'peso_neto_kg',
    'importe',
    'pesada_at',
    'created_by',
])]
class PesadaDespachoProducto extends Model
{
    public const PRICE_CATALOG = 'CATALOGO';

    public const PRICE_MANUAL = 'MANUAL';

    protected $table = 'pesadas_despacho_productos';

    /** @return BelongsTo<TicketDespachoProducto, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketDespachoProducto::class, 'ticket_despacho_producto_id');
    }

    /** @return BelongsTo<ProductoDespacho, $this> */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoDespacho::class, 'producto_despacho_id');
    }

    /** @return BelongsTo<VariacionProductoDespacho, $this> */
    public function variacion(): BelongsTo
    {
        return $this->belongsTo(VariacionProductoDespacho::class, 'variacion_producto_despacho_id');
    }

    /** @return BelongsTo<LecturaBalanza, $this> */
    public function lecturaBalanza(): BelongsTo
    {
        return $this->belongsTo(LecturaBalanza::class, 'lectura_balanza_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'precio_catalogo_snapshot' => 'decimal:4',
            'precio_venta_snapshot' => 'decimal:4',
            'cantidad' => 'integer',
            'peso_leido_kg' => 'decimal:3',
            'merma_catalogo_gramos_unidad' => 'integer',
            'merma_aplicada_gramos_unidad' => 'integer',
            'merma_total_gramos' => 'integer',
            'tara_gramos' => 'integer',
            'peso_neto_kg' => 'decimal:3',
            'importe' => 'decimal:2',
            'pesada_at' => 'datetime',
        ];
    }
}
