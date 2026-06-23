<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'programacion_id',
    'proveedor_vehiculo_id',
    'conductor_id',
    'conductor_nombre_snapshot',
    'conductor_dni_snapshot',
    'numero_visita',
    'orden_llegada',
    'hora_estimada',
    'estado',
    'observaciones',
    'llegada_at',
    'recepcion_iniciada_at',
    'completada_at',
    'estado_actualizado_por',
    'created_by',
])]
class ProgramacionRecepcionDetalle extends Model
{
    public const STATUS_PENDING = 'PENDIENTE';

    public const STATUS_CANCELLED = 'CANCELADA';

    protected $table = 'programacion_recepcion_detalles';

    /**
     * @return BelongsTo<ProgramacionRecepcion, $this>
     */
    public function programacion(): BelongsTo
    {
        return $this->belongsTo(ProgramacionRecepcion::class, 'programacion_id');
    }

    /**
     * @return BelongsTo<ProveedorVehiculo, $this>
     */
    public function proveedorVehiculo(): BelongsTo
    {
        return $this->belongsTo(ProveedorVehiculo::class, 'proveedor_vehiculo_id');
    }

    protected function casts(): array
    {
        return [
            'hora_estimada' => 'datetime:H:i:s',
            'llegada_at' => 'datetime',
            'recepcion_iniciada_at' => 'datetime',
            'completada_at' => 'datetime',
        ];
    }
}
