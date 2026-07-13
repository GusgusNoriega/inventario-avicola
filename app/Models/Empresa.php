<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'razon_social',
    'nombre_comercial',
    'ruc',
    'pais_codigo',
    'moneda',
    'zona_horaria',
    'hora_corte_operativo',
    'sunat_habilitado',
    'estado',
])]
class Empresa extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    /**
     * @return HasMany<Tercero, $this>
     */
    public function terceros(): HasMany
    {
        return $this->hasMany(Tercero::class);
    }

    /**
     * @return HasMany<AjustePesoMinorista, $this>
     */
    public function ajustesPesoMinorista(): HasMany
    {
        return $this->hasMany(AjustePesoMinorista::class);
    }

    /**
     * @return HasMany<EntidadFinanciera, $this>
     */
    public function entidadesFinancieras(): HasMany
    {
        return $this->hasMany(EntidadFinanciera::class);
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
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }
}
