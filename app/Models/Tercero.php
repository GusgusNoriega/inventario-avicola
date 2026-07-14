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
    'es_cliente_interno',
    'telefono',
    'email',
    'observaciones',
    'estado',
])]
class Tercero extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected function casts(): array
    {
        return [
            'es_cliente_interno' => 'boolean',
        ];
    }

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
     * @return HasMany<MovimientoJava, $this>
     */
    public function movimientosJavas(): HasMany
    {
        return $this->hasMany(MovimientoJava::class, 'cliente_id');
    }

    /**
     * @return HasMany<EntidadFinanciera, $this>
     */
    public function entidadesFinancieras(): HasMany
    {
        return $this->hasMany(EntidadFinanciera::class, 'proveedor_id');
    }

    /**
     * @return HasMany<CostoCompraPesada, $this>
     */
    public function costosCompra(): HasMany
    {
        return $this->hasMany(CostoCompraPesada::class, 'proveedor_id');
    }

    /**
     * @return HasMany<Comprobante, $this>
     */
    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class);
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagosComoTercero(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagosComoCliente(): HasMany
    {
        return $this->hasMany(Pago::class, 'cliente_id');
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagosComoProveedor(): HasMany
    {
        return $this->hasMany(Pago::class, 'proveedor_id');
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
