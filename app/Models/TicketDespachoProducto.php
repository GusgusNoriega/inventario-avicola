<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'sucursal_id',
    'referencia_externa',
    'numero_lista',
    'codigo',
    'titulo_ticket_snapshot',
    'fecha_operativa',
    'cliente_id',
    'tipo_cliente',
    'cliente_tipo_documento_snapshot',
    'cliente_numero_documento_snapshot',
    'cliente_nombre_snapshot',
    'moneda',
    'cantidad_total',
    'peso_leido_total_kg',
    'merma_total_gramos',
    'tara_total_gramos',
    'peso_neto_total_kg',
    'subtotal',
    'total',
    'estado',
    'registrado_at',
    'created_by',
])]
class TicketDespachoProducto extends Model
{
    public const STATUS_REGISTERED = 'REGISTRADO';

    public const CUSTOMER_REGISTERED = 'CLIENTE_REGISTRADO';

    public const CUSTOMER_PUBLIC = 'VENTA_PUBLICO';

    public const PUBLIC_SALE_LABEL = 'Venta al público';

    protected $table = 'tickets_despacho_productos';

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return BelongsTo<Sucursal, $this> */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** @return BelongsTo<Tercero, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'cliente_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PesadaDespachoProducto, $this> */
    public function pesadas(): HasMany
    {
        return $this->hasMany(PesadaDespachoProducto::class, 'ticket_despacho_producto_id')
            ->orderBy('numero');
    }

    protected function casts(): array
    {
        return [
            'fecha_operativa' => 'date',
            'numero_lista' => 'integer',
            'cantidad_total' => 'integer',
            'peso_leido_total_kg' => 'decimal:3',
            'merma_total_gramos' => 'integer',
            'tara_total_gramos' => 'integer',
            'peso_neto_total_kg' => 'decimal:3',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'registrado_at' => 'datetime',
        ];
    }
}
