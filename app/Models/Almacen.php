<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sucursal_id',
    'codigo',
    'nombre',
    'direccion',
    'permite_stock_negativo',
    'estado',
])]
class Almacen extends Model
{
    protected $table = 'almacenes';

    /**
     * @return HasMany<TicketDespacho, $this>
     */
    public function ticketsDestino(): HasMany
    {
        return $this->hasMany(TicketDespacho::class, 'almacen_destino_id');
    }
}
