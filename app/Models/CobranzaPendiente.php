<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cobranza_id',
    'pago_id',
    'importe',
])]
class CobranzaPendiente extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cobranza_pendientes';

    /** @return BelongsTo<Cobranza, $this> */
    public function cobranza(): BelongsTo
    {
        return $this->belongsTo(Cobranza::class);
    }

    /** @return BelongsTo<Pago, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    protected function casts(): array
    {
        return [
            'importe' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
