<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'proveedor_id',
    'comprobante_id',
    'pago_inicial_id',
    'codigo',
    'idempotency_key',
    'tipo_documento',
    'numero_documento',
    'numero_documento_activo',
    'fecha_compra',
    'fecha_vencimiento',
    'condicion',
    'moneda',
    'subtotal',
    'impuesto',
    'total',
    'estado',
    'observaciones',
    'created_by',
    'anulada_por',
    'anulada_at',
    'motivo_anulacion',
])]
class Compra extends Model
{
    public const CONDITION_CASH = 'CONTADO';

    public const CONDITION_CREDIT = 'CREDITO';

    public const CONDITION_LEGACY = 'LEGADO';

    public const STATUS_REGISTERED = 'REGISTRADA';

    public const STATUS_VOIDED = 'ANULADA';

    protected $table = 'compras';

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return BelongsTo<Tercero, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'proveedor_id');
    }

    /** @return BelongsTo<Comprobante, $this> */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    /** @return BelongsTo<Pago, $this> */
    public function pagoInicial(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_inicial_id');
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

    /** @return HasMany<CompraDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(CompraDetalle::class);
    }

    protected function casts(): array
    {
        return [
            'fecha_compra' => 'date',
            'fecha_vencimiento' => 'date',
            'subtotal' => 'decimal:2',
            'impuesto' => 'decimal:2',
            'total' => 'decimal:2',
            'anulada_at' => 'datetime',
        ];
    }
}
