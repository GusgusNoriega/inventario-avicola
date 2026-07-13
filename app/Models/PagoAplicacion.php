<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pago_id',
    'comprobante_id',
    'lado',
    'importe_aplicado',
    'created_by',
])]
class PagoAplicacion extends Model
{
    public const UPDATED_AT = null;

    public const SIDE_RECEIVABLE = 'CXC';

    public const SIDE_PAYABLE = 'CXP';

    public $incrementing = false;

    protected $table = 'pago_aplicaciones';

    protected $primaryKey = null;

    /**
     * @return BelongsTo<Pago, $this>
     */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    /**
     * @return BelongsTo<Comprobante, $this>
     */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
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
            'importe_aplicado' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
