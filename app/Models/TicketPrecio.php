<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ticket_id',
    'tipo_pollo_id',
    'precio_historial_id',
    'precio_kg',
    'origen_precio',
    'congelado_por',
])]
class TicketPrecio extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ticket_precios';

    /**
     * @return BelongsTo<TicketDespacho, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketDespacho::class, 'ticket_id');
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
            'created_at' => 'datetime',
        ];
    }
}
