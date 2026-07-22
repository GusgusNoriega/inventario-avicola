<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'balanza_id',
    'peso_kg',
    'trama_cruda',
    'modo_conexion',
    'dispositivo',
    'capturada_at',
    'capturada_por',
])]
class LecturaBalanza extends Model
{
    public $timestamps = false;

    protected $table = 'lecturas_balanza';

    /** @return BelongsTo<Balanza, $this> */
    public function balanza(): BelongsTo
    {
        return $this->belongsTo(Balanza::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturada_por');
    }

    /** @return HasOne<Pesada, $this> */
    public function pesada(): HasOne
    {
        return $this->hasOne(Pesada::class);
    }

    protected function casts(): array
    {
        return [
            'peso_kg' => 'decimal:3',
            'capturada_at' => 'datetime',
        ];
    }
}
