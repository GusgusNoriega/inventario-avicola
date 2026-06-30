<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'empresa_id',
    'sucursal_id',
    'cliente_id',
    'tipo',
    'cantidad',
    'ticket_despacho_id',
    'vehiculo_id',
    'conductor_id',
    'fecha_movimiento',
    'observaciones',
    'created_by',
])]
class MovimientoJava extends Model
{
    public const TYPE_DISPATCH = 'DESPACHO';

    public const TYPE_RECEIPT = 'RECEPCION';

    protected $table = 'movimientos_javas';

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'cliente_id');
    }

    public function ticketDespacho(): BelongsTo
    {
        return $this->belongsTo(TicketDespacho::class, 'ticket_despacho_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id');
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'conductor_id');
    }

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'fecha_movimiento' => 'datetime',
        ];
    }
}
