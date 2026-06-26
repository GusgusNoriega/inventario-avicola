<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ticket_id',
    'numero',
    'tipo_pollo_id',
    'condicion_pollo',
    'tipo_java_id',
    'lectura_balanza_id',
    'proveedor_origen_id',
    'almacen_origen_id',
    'vehiculo_id',
    'programacion_recepcion_detalle_id',
    'placa_snapshot',
    'origen_peso',
    'aves_por_java',
    'cantidad_javas',
    'cantidad_aves',
    'peso_java_kg_snapshot',
    'peso_leido_kg',
    'peso_bruto_kg',
    'tara_total_kg',
    'peso_neto_kg',
    'pesada_at',
    'estado',
    'anulada_por',
    'anulada_at',
    'motivo_anulacion',
    'created_by',
])]
class Pesada extends Model
{
    public const CHICKEN_CONDITION_LIVE = 'VIVO';

    public const CHICKEN_CONDITION_DEAD = 'MUERTO';

    public const STATUS_ACTIVE = 'ACTIVA';

    protected $table = 'pesadas';

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

    /**
     * @return BelongsTo<TipoJava, $this>
     */
    public function tipoJava(): BelongsTo
    {
        return $this->belongsTo(TipoJava::class);
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function proveedorOrigen(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'proveedor_origen_id');
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacenOrigen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_origen_id');
    }

    /**
     * @return BelongsTo<Vehiculo, $this>
     */
    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    protected function casts(): array
    {
        return [
            'peso_java_kg_snapshot' => 'decimal:3',
            'peso_leido_kg' => 'decimal:3',
            'peso_bruto_kg' => 'decimal:3',
            'tara_total_kg' => 'decimal:3',
            'peso_neto_kg' => 'decimal:3',
            'pesada_at' => 'datetime',
            'anulada_at' => 'datetime',
        ];
    }
}
