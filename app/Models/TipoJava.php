<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['codigo', 'nombre', 'peso_kg', 'estado'])]
class TipoJava extends Model
{
    protected $table = 'tipos_java';

    protected function casts(): array
    {
        return [
            'peso_kg' => 'decimal:3',
        ];
    }
}
