<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'empresa_id',
    'sucursal_id',
    'estacion',
    'metodo_pago_id',
    'cuenta_destino_id',
])]
class ConfiguracionDespachoMinorista extends Model
{
    protected $table = 'configuraciones_despacho_minorista';

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return BelongsTo<Sucursal, $this> */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** @return BelongsTo<MetodoPago, $this> */
    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class);
    }

    /** @return BelongsTo<CuentaFinanciera, $this> */
    public function cuentaDestino(): BelongsTo
    {
        return $this->belongsTo(CuentaFinanciera::class, 'cuenta_destino_id');
    }

    protected function casts(): array
    {
        return [
            'estacion' => 'integer',
        ];
    }
}
