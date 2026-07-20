<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'empresa_id',
    'codigo',
    'tercero_id',
    'tipo',
    'cliente_id',
    'proveedor_id',
    'cuenta_origen_id',
    'cuenta_destino_id',
    'metodo_pago_id',
    'direccion',
    'fecha_hora',
    'metodo',
    'referencia',
    'moneda',
    'importe',
    'estado',
    'idempotency_key',
    'reversa_de_pago_id',
    'observaciones',
    'created_by',
    'anulada_por',
    'anulada_at',
    'motivo_anulacion',
])]
class Pago extends Model
{
    public const TYPE_CUSTOMER_COLLECTION = 'COBRO_CLIENTE';

    public const TYPE_DIRECT_PAYMENT = 'PAGO_DIRECTO';

    public const TYPE_PROVIDER_PAYMENT = 'PAGO_PROVEEDOR';

    public const TYPE_PROVIDER_CREDIT = 'SALDO_FAVOR_PROVEEDOR';

    public const TYPE_RETAIL_COLLECTION = 'COBRO_MINORISTA';

    public const TYPE_CUSTOMER_REFUND = 'REEMBOLSO_CLIENTE';

    public const TYPE_OPENING_BALANCE = 'SALDO_INICIAL';

    public const TYPE_ADJUSTMENT = 'AJUSTE';

    public const TYPE_INTERNAL_TRANSFER = 'TRANSFERENCIA_INTERNA';

    public const TYPES = [
        self::TYPE_CUSTOMER_COLLECTION,
        self::TYPE_DIRECT_PAYMENT,
        self::TYPE_PROVIDER_PAYMENT,
        self::TYPE_PROVIDER_CREDIT,
        self::TYPE_RETAIL_COLLECTION,
        self::TYPE_CUSTOMER_REFUND,
        self::TYPE_OPENING_BALANCE,
        self::TYPE_ADJUSTMENT,
        self::TYPE_INTERNAL_TRANSFER,
    ];

    /**
     * Movimientos cuyo importe no aplicado representa saldo a favor de la
     * empresa con un proveedor y puede asignarse posteriormente a una CXP.
     */
    public const PROVIDER_CREDIT_SOURCE_TYPES = [
        self::TYPE_PROVIDER_PAYMENT,
        self::TYPE_PROVIDER_CREDIT,
    ];

    public const DIRECTION_INCOME = 'INGRESO';

    public const DIRECTION_EXPENSE = 'EGRESO';

    public const DIRECTION_NO_FLOW = 'SIN_FLUJO';

    public const STATUS_REGISTERED = 'REGISTRADO';

    public const STATUS_VOIDED = 'ANULADO';

    protected $table = 'pagos';

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'cliente_id');
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'proveedor_id');
    }

    /**
     * @return BelongsTo<CuentaFinanciera, $this>
     */
    public function cuentaOrigen(): BelongsTo
    {
        return $this->belongsTo(CuentaFinanciera::class, 'cuenta_origen_id');
    }

    /**
     * @return BelongsTo<CuentaFinanciera, $this>
     */
    public function cuentaDestino(): BelongsTo
    {
        return $this->belongsTo(CuentaFinanciera::class, 'cuenta_destino_id');
    }

    /**
     * @return BelongsTo<MetodoPago, $this>
     */
    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    /**
     * La fila de pago original que esta fila revierte.
     *
     * @return BelongsTo<Pago, $this>
     */
    public function pagoReversado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversa_de_pago_id');
    }

    /**
     * @return HasOne<Pago, $this>
     */
    public function reversa(): HasOne
    {
        return $this->hasOne(self::class, 'reversa_de_pago_id');
    }

    /**
     * @return HasOne<Compra, $this>
     */
    public function compraInicial(): HasOne
    {
        return $this->hasOne(Compra::class, 'pago_inicial_id');
    }

    /**
     * @return HasMany<PagoAplicacion, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(PagoAplicacion::class);
    }

    /**
     * @return BelongsToMany<Comprobante, $this>
     */
    public function comprobantes(): BelongsToMany
    {
        return $this->belongsToMany(Comprobante::class, 'pago_aplicaciones')
            ->withPivot(['lado', 'importe_aplicado', 'created_by', 'created_at']);
    }

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
            'importe' => 'decimal:2',
            'anulada_at' => 'datetime',
        ];
    }
}
