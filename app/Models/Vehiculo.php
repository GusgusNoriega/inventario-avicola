<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'placa',
    'tercero_propietario_id',
    'conductor_habitual_id',
    'marca',
    'modelo',
    'color',
    'descripcion',
    'estado',
])]
class Vehiculo extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    protected $table = 'vehiculos';

    /**
     * @return HasMany<ProveedorVehiculo, $this>
     */
    public function proveedores(): HasMany
    {
        return $this->hasMany(ProveedorVehiculo::class, 'vehiculo_id');
    }
}
