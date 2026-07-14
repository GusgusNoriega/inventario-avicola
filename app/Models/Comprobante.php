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
    'tercero_id',
    'operacion',
    'naturaleza',
    'tipo_documento',
    'codigo',
    'origen_codigo',
    'origen_clave',
    'fecha_emision',
    'fecha_vencimiento',
    'moneda',
    'subtotal',
    'impuesto',
    'total',
    'saldo_pendiente',
    'estado',
    'contraparte_tipo_documento_snapshot',
    'contraparte_numero_documento_snapshot',
    'contraparte_nombre_snapshot',
    'contraparte_direccion_snapshot',
    'created_by',
    'anulada_por',
    'anulada_at',
    'motivo_anulacion',
])]
class Comprobante extends Model
{
    public const OPERATION_SALE = 'VENTA';

    public const OPERATION_PURCHASE = 'COMPRA';

    public const NATURE_CHARGE = 'CARGO';

    public const NATURE_CREDIT = 'ABONO';

    public const STATUS_DRAFT = 'BORRADOR';

    public const STATUS_PENDING = 'PENDIENTE';

    public const STATUS_PARTIAL = 'PARCIAL';

    public const STATUS_PAID = 'PAGADO';

    public const STATUS_VOIDED = 'ANULADO';

    protected $table = 'comprobantes';

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
     * @return HasMany<PagoAplicacion, $this>
     */
    public function aplicacionesPago(): HasMany
    {
        return $this->hasMany(PagoAplicacion::class);
    }

    /**
     * @return BelongsToMany<Pago, $this>
     */
    public function pagos(): BelongsToMany
    {
        return $this->belongsToMany(Pago::class, 'pago_aplicaciones')
            ->withPivot(['lado', 'importe_aplicado', 'created_by', 'created_at']);
    }

    /**
     * @return BelongsToMany<TicketDespacho, $this>
     */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(TicketDespacho::class, 'comprobante_tickets', 'comprobante_id', 'ticket_id')
            ->withPivot('importe_aplicado');
    }

    /**
     * @return BelongsToMany<Pesada, $this>
     */
    public function pesadas(): BelongsToMany
    {
        return $this->belongsToMany(Pesada::class, 'comprobante_pesadas', 'comprobante_id', 'pesada_id')
            ->withPivot('importe_aplicado');
    }

    /**
     * @return HasOne<Compra, $this>
     */
    public function compra(): HasOne
    {
        return $this->hasOne(Compra::class);
    }

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
            'subtotal' => 'decimal:2',
            'impuesto' => 'decimal:2',
            'total' => 'decimal:2',
            'saldo_pendiente' => 'decimal:2',
            'anulada_at' => 'datetime',
        ];
    }
}
