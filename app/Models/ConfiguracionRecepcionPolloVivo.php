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
    'aves_por_java_macho',
    'aves_por_java_hembra',
    'cantidad_javas_predeterminada',
    'tipo_java_predeterminado_id',
    'updated_by',
])]
class ConfiguracionRecepcionPolloVivo extends Model
{
    protected $table = 'configuraciones_recepcion_pollo_vivo';

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    protected function casts(): array
    {
        return [
            'aves_por_java_macho' => 'integer',
            'aves_por_java_hembra' => 'integer',
            'cantidad_javas_predeterminada' => 'integer',
            'tipo_java_predeterminado_id' => 'integer',
        ];
    }
}
