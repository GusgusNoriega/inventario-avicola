<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pesada_id',
    'proveedor_id',
    'precio_historial_id',
    'precio_kg',
    'peso_kg',
    'importe',
    'estado',
    'origen',
    'created_by',
])]
class CostoCompraPesada extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_VOIDED = 'ANULADO';

    public const ORIGIN_AUTOMATIC = 'AUTOMATICO';

    public const ORIGIN_MANUAL = 'MANUAL';

    public const ORIGIN_REBUILT = 'RECONSTRUIDO';

    protected $table = 'costos_compra_pesadas';

    /**
     * @return BelongsTo<Pesada, $this>
     */
    public function pesada(): BelongsTo
    {
        return $this->belongsTo(Pesada::class);
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'proveedor_id');
    }

    /**
     * @return BelongsTo<PrecioHistorial, $this>
     */
    public function precioHistorial(): BelongsTo
    {
        return $this->belongsTo(PrecioHistorial::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'precio_kg' => 'decimal:4',
            'peso_kg' => 'decimal:3',
            'importe' => 'decimal:2',
        ];
    }
}
