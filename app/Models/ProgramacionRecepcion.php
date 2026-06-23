<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sucursal_id',
    'fecha_operativa',
    'estado',
    'observaciones',
    'publicada_por',
    'publicada_at',
    'cerrada_por',
    'cerrada_at',
    'created_by',
])]
class ProgramacionRecepcion extends Model
{
    public const STATUS_DRAFT = 'BORRADOR';

    public const STATUS_PUBLISHED = 'PUBLICADA';

    public const STATUS_CLOSED = 'CERRADA';

    protected $table = 'programaciones_recepcion';

    /**
     * @return HasMany<ProgramacionRecepcionDetalle, $this>
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(ProgramacionRecepcionDetalle::class, 'programacion_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_operativa' => 'date',
            'publicada_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }
}
