<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'empresa_id',
    'placa',
    'marca',
    'modelo',
    'color',
    'descripcion',
    'estado',
])]
class Vehiculo extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'vehiculos';

    protected $attributes = [
        'es_propio' => true,
    ];

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return HasMany<ProveedorVehiculo, $this>
     */
    public function proveedores(): HasMany
    {
        return $this->hasMany(ProveedorVehiculo::class, 'vehiculo_id');
    }

    /**
     * @return HasOne<ProveedorVehiculo, $this>
     */
    public function asignacionProveedorActiva(): HasOne
    {
        return $this->hasOne(ProveedorVehiculo::class, 'vehiculo_id')
            ->where('estado', ProveedorVehiculo::STATUS_ACTIVE)
            ->whereNull('vigente_hasta')
            ->latestOfMany();
    }

    /**
     * @return HasMany<TicketDespacho, $this>
     */
    public function ticketsEntregados(): HasMany
    {
        return $this->hasMany(TicketDespacho::class, 'vehiculo_entrega_id');
    }

    protected function casts(): array
    {
        return [
            'es_propio' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Vehiculo $vehicle): void {
            $vehicle->es_propio = true;
            $vehicle->tercero_propietario_id = null;
        });
    }
}
