<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'jornada_id',
    'codigo',
    'referencia_externa',
    'canal',
    'tipo_operacion',
    'cliente_destino_id',
    'almacen_destino_id',
    'vehiculo_entrega_id',
    'conductor_entrega_id',
    'estado',
    'observaciones',
    'cerrado_por',
    'cerrado_at',
    'created_by',
])]
class TicketDespacho extends Model
{
    public const CHANNEL_WHOLESALE = 'MAYORISTA';

    public const CHANNEL_RETAIL = 'MINORISTA';

    public const OPERATION_DISPATCH = 'DESPACHO';

    public const OPERATION_RETURN = 'DEVOLUCION';

    public const STATUS_OPEN = 'ABIERTO';

    public const STATUS_CLOSED = 'CERRADO';

    protected $table = 'tickets_despacho';

    /**
     * @return BelongsTo<JornadaOperativa, $this>
     */
    public function jornada(): BelongsTo
    {
        return $this->belongsTo(JornadaOperativa::class, 'jornada_id');
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function clienteDestino(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'cliente_destino_id');
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacenDestino(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_destino_id');
    }

    /**
     * @return BelongsTo<Vehiculo, $this>
     */
    public function vehiculoEntrega(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_entrega_id');
    }

    /**
     * @return BelongsTo<Conductor, $this>
     */
    public function conductorEntrega(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'conductor_entrega_id');
    }

    /**
     * @return HasMany<Pesada, $this>
     */
    public function pesadas(): HasMany
    {
        return $this->hasMany(Pesada::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketPrecio, $this>
     */
    public function precios(): HasMany
    {
        return $this->hasMany(TicketPrecio::class, 'ticket_id');
    }

    /**
     * @return HasOne<MovimientoJava, $this>
     */
    public function movimientoJavas(): HasOne
    {
        return $this->hasOne(MovimientoJava::class, 'ticket_despacho_id');
    }

    /**
     * @return BelongsToMany<Comprobante, $this>
     */
    public function comprobantes(): BelongsToMany
    {
        return $this->belongsToMany(Comprobante::class, 'comprobante_tickets', 'ticket_id', 'comprobante_id')
            ->withPivot('importe_aplicado');
    }

    protected function casts(): array
    {
        return [
            'cerrado_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
