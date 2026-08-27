<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recepcion_id',
    'ticket_despacho_id',
    'movimiento_inventario_id',
    'columna',
    'request_hash',
    'cantidad_javas_aplicada',
    'revision',
    'created_by',
])]
class RecepcionPolloVivoTicket extends Model
{
    protected $table = 'recepcion_pollo_vivo_tickets';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionPolloVivo::class, 'recepcion_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketDespacho::class, 'ticket_despacho_id');
    }

    protected function casts(): array
    {
        return [
            'columna' => 'integer',
            'cantidad_javas_aplicada' => 'integer',
            'revision' => 'integer',
        ];
    }
}
