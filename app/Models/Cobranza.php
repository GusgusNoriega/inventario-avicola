<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'cobrador_id',
    'cobrador_nombre_snapshot',
    'codigo',
    'idempotency_key',
    'payload_hash',
    'cuenta_destino_id',
    'proveedor_id',
    'metodo_pago_id',
    'fecha_hora',
    'referencia',
    'moneda',
    'importe_total',
    'observaciones',
    'estado',
    'created_by',
    'anulada_por',
    'anulada_at',
    'motivo_anulacion',
])]
class Cobranza extends Model
{
    public const STATUS_REGISTERED = 'REGISTRADO';

    public const STATUS_VOIDED = 'ANULADO';

    protected $table = 'cobranzas';

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return BelongsTo<Cobrador, $this> */
    public function cobrador(): BelongsTo
    {
        return $this->belongsTo(Cobrador::class);
    }

    /** @return BelongsTo<CuentaFinanciera, $this> */
    public function cuentaDestino(): BelongsTo
    {
        return $this->belongsTo(CuentaFinanciera::class, 'cuenta_destino_id');
    }

    /** @return BelongsTo<Tercero, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'proveedor_id');
    }

    /** @return BelongsTo<MetodoPago, $this> */
    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    /** @return HasMany<CobranzaDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(CobranzaDetalle::class)->orderBy('orden');
    }

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
            'importe_total' => 'decimal:2',
            'anulada_at' => 'datetime',
        ];
    }
}
