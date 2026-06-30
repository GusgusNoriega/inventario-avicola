<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'nombre_completo',
    'tipo_documento',
    'numero_documento',
    'telefono',
    'estado',
])]
class Conductor extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'conductores';

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return HasMany<TicketDespacho, $this>
     */
    public function ticketsEntregados(): HasMany
    {
        return $this->hasMany(TicketDespacho::class, 'conductor_entrega_id');
    }
}
