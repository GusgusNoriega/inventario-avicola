<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'cobranza_id',
    'idempotency_key',
    'payload_hash',
    'importe_pendiente_antes',
    'importe_asignado',
    'importe_pendiente_despues',
    'pago_pendiente_anterior_id',
    'pago_reversa_id',
    'pago_pendiente_nuevo_id',
    'created_by',
])]
class CobranzaAsignacion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cobranza_asignaciones';

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return BelongsTo<Cobranza, $this> */
    public function cobranza(): BelongsTo
    {
        return $this->belongsTo(Cobranza::class);
    }

    /** @return HasMany<CobranzaDetalle, $this> */
    public function detalles(): HasMany
    {
        return $this->hasMany(CobranzaDetalle::class, 'asignacion_id')->orderBy('orden');
    }

    /** @return BelongsTo<Pago, $this> */
    public function pagoPendienteAnterior(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_pendiente_anterior_id');
    }

    /** @return BelongsTo<Pago, $this> */
    public function pagoReversa(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_reversa_id');
    }

    /** @return BelongsTo<Pago, $this> */
    public function pagoPendienteNuevo(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_pendiente_nuevo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'importe_pendiente_antes' => 'decimal:2',
            'importe_asignado' => 'decimal:2',
            'importe_pendiente_despues' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
