<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'empresa_id',
    'cantidad_total',
    'cantidad_total_bandejas',
    'updated_by',
])]
class InventarioJava extends Model
{
    protected $table = 'inventarios_javas';

    protected function casts(): array
    {
        return [
            'cantidad_total' => 'integer',
            'cantidad_total_bandejas' => 'integer',
        ];
    }
}
