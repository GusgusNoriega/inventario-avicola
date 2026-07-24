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
    'categoria',
    'concepto',
    'destino',
    'numero_documento',
    'estado',
    'created_by',
    'anulada_por',
    'anulada_at',
    'motivo_anulacion',
])]
class GastoEmpresa extends Model
{
    public const STATUS_REGISTERED = 'REGISTRADO';

    public const STATUS_VOIDED = 'ANULADO';

    public const CATEGORIES = [
        'MANTENIMIENTO',
        'INDUMENTARIA',
        'SERVICIOS',
        'TRANSPORTE',
        'ALIMENTACION',
        'IMPUESTOS',
        'SUMINISTROS',
        'OTRO',
    ];

    protected $table = 'gastos_empresa';

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    protected function casts(): array
    {
        return ['anulada_at' => 'datetime'];
    }
}
