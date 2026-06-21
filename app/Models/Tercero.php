<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'tipo_documento',
    'numero_documento',
    'nombre_razon_social',
    'direccion',
    'telefono',
    'email',
    'observaciones',
    'estado',
])]
class Tercero extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return HasMany<TerceroRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(TerceroRole::class);
    }

    /**
     * @return HasMany<ListaPrecio, $this>
     */
    public function listasPrecios(): HasMany
    {
        return $this->hasMany(ListaPrecio::class);
    }

    /**
     * @return HasMany<TicketDespacho, $this>
     */
    public function ticketsDestino(): HasMany
    {
        return $this->hasMany(TicketDespacho::class, 'cliente_destino_id');
    }

    /**
     * @return HasMany<ProveedorVehiculo, $this>
     */
    public function vehiculosProveedor(): HasMany
    {
        return $this->hasMany(ProveedorVehiculo::class, 'proveedor_id');
    }

    /**
     * @return HasMany<Pesada, $this>
     */
    public function pesadasOrigen(): HasMany
    {
        return $this->hasMany(Pesada::class, 'proveedor_origen_id');
    }

    /**
     * @param  Builder<Tercero>  $query
     * @return Builder<Tercero>
     */
    public function scopeConRol(Builder $query, string $role): Builder
    {
        return $query->whereHas(
            'roles',
            fn (Builder $roleQuery) => $roleQuery->where('rol', $role)
        );
    }
}
