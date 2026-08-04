<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cobranza_id',
    'asignacion_id',
    'pago_id',
    'cliente_id',
    'fecha_recepcion',
    'medio_recepcion',
    'importe',
    'orden',
])]
class CobranzaDetalle extends Model
{
    public const UPDATED_AT = null;

    public const RECEIPT_METHOD_CASH = 'EFECTIVO';

    protected $table = 'cobranza_detalles';

    /** @return BelongsTo<Cobranza, $this> */
    public function cobranza(): BelongsTo
    {
        return $this->belongsTo(Cobranza::class);
    }

    /** @return BelongsTo<CobranzaAsignacion, $this> */
    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(CobranzaAsignacion::class, 'asignacion_id');
    }

    /** @return BelongsTo<Pago, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    /** @return BelongsTo<Tercero, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'cliente_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_recepcion' => 'date',
            'importe' => 'decimal:2',
            'orden' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
