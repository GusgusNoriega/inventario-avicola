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
    'modulo_origen',
    'tipo_operacion',
    'cliente_destino_id',
    'almacen_destino_id',
    'vehiculo_entrega_id',
    'conductor_entrega_id',
    'asignacion_transporte_posterior',
    'estado',
    'observaciones',
    'cerrado_por',
    'cerrado_at',
    'anulado_por',
    'anulado_at',
    'motivo_anulacion',
    'created_by',
])]
class TicketDespacho extends Model
{
    public const CHANNEL_WHOLESALE = 'MAYORISTA';

    public const CHANNEL_RETAIL = 'MINORISTA';

    public const SOURCE_WHOLESALE_TWO = 'MODULO_DESPACHO_MAYORISTA_2';

    public const PUBLIC_SALE_LABEL = 'Venta público';

    public const OPERATION_DISPATCH = 'DESPACHO';

    public const OPERATION_RETURN = 'DEVOLUCION';

    public const DELIVERY_MODE_CUSTOMER_PICKUP = 'CUSTOMER_PICKUP';

    public const DELIVERY_MODE_COMPANY_TRUCK = 'COMPANY_TRUCK';

    public const DELIVERY_MODE_PENDING_ASSIGNMENT = 'PENDING_ASSIGNMENT';

    public const STATUS_OPEN = 'ABIERTO';

    public const STATUS_CLOSED = 'CERRADO';

    public const STATUS_VOIDED = 'ANULADO';

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
     * @return BelongsTo<User, $this>
     */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
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

    public function resolvedDeliveryMode(): ?string
    {
        if (
            $this->canal !== self::CHANNEL_RETAIL
            || $this->tipo_operacion !== self::OPERATION_DISPATCH
        ) {
            return null;
        }

        if ($this->vehiculo_entrega_id || $this->conductor_entrega_id) {
            return self::DELIVERY_MODE_COMPANY_TRUCK;
        }

        if ($this->asignacion_transporte_posterior) {
            return self::DELIVERY_MODE_PENDING_ASSIGNMENT;
        }

        if (! $this->cliente_destino_id) {
            return null;
        }

        $weighings = $this->relationLoaded('pesadas')
            ? $this->pesadas
            : $this->pesadas()
                ->where('estado', Pesada::STATUS_ACTIVE)
                ->get(['cantidad_bandejas', 'estado']);
        $hasActiveTrays = $weighings->contains(
            fn (Pesada $weighing): bool => $weighing->estado === Pesada::STATUS_ACTIVE
                && (int) $weighing->cantidad_bandejas > 0
        );

        if (! $hasActiveTrays) {
            return null;
        }

        return self::DELIVERY_MODE_CUSTOMER_PICKUP;
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
            'anulado_at' => 'datetime',
            'asignacion_transporte_posterior' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
