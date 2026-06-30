<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'placa',
    'tercero_propietario_id',
    'marca',
    'modelo',
    'color',
    'descripcion',
    'es_propio',
    'estado',
])]
class Vehiculo extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'vehiculos';

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

    protected function casts(): array
    {
        return [
            'es_propio' => 'boolean',
        ];
    }
}
