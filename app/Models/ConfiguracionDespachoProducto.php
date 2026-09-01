<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'empresa_id',
    'sucursal_id',
    'merma_preset_1_gramos_unidad',
    'merma_preset_2_gramos_unidad',
    'merma_preset_3_gramos_unidad',
])]
class ConfiguracionDespachoProducto extends Model
{
    protected $table = 'configuraciones_despacho_productos';

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return BelongsTo<Sucursal, $this> */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    protected function casts(): array
    {
        return [
            'merma_preset_1_gramos_unidad' => 'integer',
            'merma_preset_2_gramos_unidad' => 'integer',
            'merma_preset_3_gramos_unidad' => 'integer',
        ];
    }
}
