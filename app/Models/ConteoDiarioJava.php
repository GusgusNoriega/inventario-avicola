<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'empresa_id',
    'jornada_id',
    'cantidad_en_empresa',
    'cantidad_esperada',
    'diferencia',
    'contado_at',
    'contado_por',
])]
class ConteoDiarioJava extends Model
{
    protected $table = 'conteos_diarios_javas';

    protected function casts(): array
    {
        return [
            'cantidad_en_empresa' => 'integer',
            'cantidad_esperada' => 'integer',
            'diferencia' => 'integer',
            'contado_at' => 'datetime',
        ];
    }
}
