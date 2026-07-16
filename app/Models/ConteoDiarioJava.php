<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'jornada_id',
    'cantidad_en_empresa',
    'cantidad_en_local',
    'cantidad_en_empresa_bandejas',
    'cantidad_en_local_bandejas',
    'cantidad_esperada',
    'cantidad_esperada_bandejas',
    'cantidad_clientes_externos',
    'cantidad_clientes_externos_bandejas',
    'cantidad_clientes_internos',
    'cantidad_clientes_internos_bandejas',
    'cantidad_total_inventario',
    'cantidad_total_inventario_bandejas',
    'diferencia',
    'diferencia_bandejas',
    'contado_at',
    'contado_por',
])]
class ConteoDiarioJava extends Model
{
    protected $table = 'conteos_diarios_javas';

    /** @return HasMany<ConteoDiarioJavaCamion, $this> */
    public function camiones(): HasMany
    {
        return $this->hasMany(ConteoDiarioJavaCamion::class, 'conteo_diario_java_id');
    }

    /** @return BelongsTo<User, $this> */
    public function contador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contado_por');
    }

    protected function casts(): array
    {
        return [
            'cantidad_en_empresa' => 'integer',
            'cantidad_en_local' => 'integer',
            'cantidad_en_empresa_bandejas' => 'integer',
            'cantidad_en_local_bandejas' => 'integer',
            'cantidad_esperada' => 'integer',
            'cantidad_esperada_bandejas' => 'integer',
            'cantidad_clientes_externos' => 'integer',
            'cantidad_clientes_externos_bandejas' => 'integer',
            'cantidad_clientes_internos' => 'integer',
            'cantidad_clientes_internos_bandejas' => 'integer',
            'cantidad_total_inventario' => 'integer',
            'cantidad_total_inventario_bandejas' => 'integer',
            'diferencia' => 'integer',
            'diferencia_bandejas' => 'integer',
            'contado_at' => 'datetime',
        ];
    }
}
