<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sucursal_id',
    'fecha_operativa',
    'estado',
    'abierta_por',
    'inicio_at',
    'cierre_programado_at',
    'cerrada_por',
    'cerrada_at',
    'observaciones',
])]
class JornadaOperativa extends Model
{
    public const STATUS_OPEN = 'ABIERTA';

    public const STATUS_CLOSED = 'CERRADA';

    public $timestamps = false;

    protected $table = 'jornadas_operativas';

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return HasMany<TicketDespacho, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(TicketDespacho::class, 'jornada_id');
    }

    /**
     * @return HasMany<MovimientoJava, $this>
     */
    public function movimientosJavas(): HasMany
    {
        return $this->hasMany(MovimientoJava::class, 'jornada_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_operativa' => 'date',
            'inicio_at' => 'datetime',
            'cierre_programado_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }
}
