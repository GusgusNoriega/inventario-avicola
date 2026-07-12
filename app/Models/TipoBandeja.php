<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['codigo', 'nombre', 'peso_kg', 'capacidad_aves', 'estado'])]
class TipoBandeja extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    protected $table = 'tipos_bandeja';

    protected function casts(): array
    {
        return [
            'peso_kg' => 'decimal:3',
            'capacidad_aves' => 'integer',
        ];
    }
}
