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
    'productos_rapidos_configurados',
    'producto_rapido_1_id',
    'producto_rapido_2_id',
    'producto_rapido_3_id',
    'producto_rapido_4_id',
    'titulo_pantalla_cliente',
    'titulo_ticket_despacho',
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
            'productos_rapidos_configurados' => 'boolean',
            'producto_rapido_1_id' => 'integer',
            'producto_rapido_2_id' => 'integer',
            'producto_rapido_3_id' => 'integer',
            'producto_rapido_4_id' => 'integer',
        ];
    }
}
