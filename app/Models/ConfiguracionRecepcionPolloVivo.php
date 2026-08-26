<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sucursal_id',
    'propietario_externo_predeterminado_id',
    'almacen_columna_1_id',
    'almacen_columna_2_id',
    'almacen_columna_3_id',
    'almacen_columna_4_id',
    'cliente_columna_3_id',
    'cliente_columna_4_id',
    'updated_by',
])]
class ConfiguracionRecepcionPolloVivo extends Model
{
    protected $table = 'configuraciones_recepcion_pollo_vivo';

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
