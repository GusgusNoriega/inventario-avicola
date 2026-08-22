<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'jornada_id',
    'origen',
    'estado',
    'created_by',
])]
class RecepcionPolloVivo extends Model
{
    public const STATUS_OPEN = 'ABIERTA';

    public const ORIGIN_DAILY_TRUCK = 'Camión del día';

    protected $table = 'recepciones_pollo_vivo';

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(JornadaOperativa::class, 'jornada_id');
    }

    public function pesadas(): HasMany
    {
        return $this->hasMany(PesadaRecepcionPolloVivo::class, 'recepcion_id');
    }
}
