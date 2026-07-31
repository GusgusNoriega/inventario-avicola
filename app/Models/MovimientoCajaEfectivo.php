<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'empresa_id',
    'pago_id',
    'codigo',
    'idempotency_key',
    'caja_id',
    'direccion',
    'contraparte_tipo',
    'cliente_id',
    'otra_caja_id',
    'detalle',
    'estado',
    'created_by',
])]
class MovimientoCajaEfectivo extends Model
{
    public const DIRECTION_INCOME = 'INGRESO';

    public const DIRECTION_EXPENSE = 'EGRESO';

    public const DIRECTIONS = [
        self::DIRECTION_INCOME,
        self::DIRECTION_EXPENSE,
    ];

    public const COUNTERPART_CUSTOMER = 'CLIENTE';

    public const COUNTERPART_CASH_REGISTER = 'OTRA_CAJA';

    public const COUNTERPART_ADMINISTRATIVE = 'ADMINISTRATIVO';

    public const COUNTERPART_TRANSPORT = 'TRANSPORTE';

    public const COUNTERPART_DEPOSIT = 'DEPOSITO';

    public const COUNTERPART_OTHER = 'OTRO';

    public const INCOME_COUNTERPARTS = [
        self::COUNTERPART_CUSTOMER,
        self::COUNTERPART_CASH_REGISTER,
        self::COUNTERPART_OTHER,
    ];

    public const EXPENSE_COUNTERPARTS = [
        self::COUNTERPART_ADMINISTRATIVE,
        self::COUNTERPART_TRANSPORT,
        self::COUNTERPART_DEPOSIT,
        self::COUNTERPART_CASH_REGISTER,
    ];

    /** @deprecated Use the direction-specific counterpart constants. */
    public const COUNTERPARTS = [
        self::COUNTERPART_CUSTOMER,
        self::COUNTERPART_CASH_REGISTER,
        self::COUNTERPART_OTHER,
        self::COUNTERPART_ADMINISTRATIVE,
        self::COUNTERPART_TRANSPORT,
        self::COUNTERPART_DEPOSIT,
    ];

    public const STATUS_REGISTERED = 'REGISTRADO';

    public const STATUS_VOIDED = 'ANULADO';

    protected $table = 'movimientos_caja_efectivo';

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return BelongsTo<Pago, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    /** @return BelongsTo<CuentaFinanciera, $this> */
    public function caja(): BelongsTo
    {
        return $this->belongsTo(CuentaFinanciera::class, 'caja_id');
    }

    /** @return BelongsTo<CuentaFinanciera, $this> */
    public function otraCaja(): BelongsTo
    {
        return $this->belongsTo(CuentaFinanciera::class, 'otra_caja_id');
    }

    /** @return BelongsTo<Tercero, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'cliente_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
