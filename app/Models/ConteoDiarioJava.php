<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'empresa_id',
    'jornada_id',
    'cantidad_en_empresa',
    'cantidad_en_empresa_bandejas',
    'cantidad_esperada',
    'cantidad_esperada_bandejas',
    'diferencia',
    'diferencia_bandejas',
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
            'cantidad_en_empresa_bandejas' => 'integer',
            'cantidad_esperada' => 'integer',
            'cantidad_esperada_bandejas' => 'integer',
            'diferencia' => 'integer',
            'diferencia_bandejas' => 'integer',
            'contado_at' => 'datetime',
        ];
    }
}
