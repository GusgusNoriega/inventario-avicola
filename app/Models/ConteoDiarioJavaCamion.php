<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conteo_diario_java_id',
    'vehiculo_id',
    'placa_snapshot',
    'cantidad_javas',
    'cantidad_bandejas',
])]
class ConteoDiarioJavaCamion extends Model
{
    protected $table = 'conteos_diarios_javas_camiones';

    /** @return BelongsTo<ConteoDiarioJava, $this> */
    public function conteo(): BelongsTo
    {
        return $this->belongsTo(ConteoDiarioJava::class, 'conteo_diario_java_id');
    }

    /** @return BelongsTo<Vehiculo, $this> */
    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    protected function casts(): array
    {
        return [
            'cantidad_javas' => 'integer',
            'cantidad_bandejas' => 'integer',
        ];
    }
}
