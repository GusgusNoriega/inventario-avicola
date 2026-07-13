<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'entidad_financiera_id',
    'tipo',
    'alias',
    'banco',
    'numero_cuenta',
    'cci',
    'moneda',
    'estado',
    'created_by',
])]
class CuentaFinanciera extends Model
{
    public const TYPE_BANK = 'BANCO';

    public const TYPE_CASH = 'CAJA';

    public const TYPE_WALLET = 'BILLETERA';

    public const TYPE_OTHER = 'OTRA';

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'cuentas_financieras';

    /**
     * @return BelongsTo<EntidadFinanciera, $this>
     */
    public function entidadFinanciera(): BelongsTo
    {
        return $this->belongsTo(EntidadFinanciera::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagosOrigen(): HasMany
    {
        return $this->hasMany(Pago::class, 'cuenta_origen_id');
    }

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagosDestino(): HasMany
    {
        return $this->hasMany(Pago::class, 'cuenta_destino_id');
    }
}
