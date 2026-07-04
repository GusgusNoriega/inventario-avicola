<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'empresa_id',
    'cantidad_total',
    'updated_by',
])]
class InventarioJava extends Model
{
    protected $table = 'inventarios_javas';

    protected function casts(): array
    {
        return [
            'cantidad_total' => 'integer',
        ];
    }
}
