<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'empresa_id',
    'sucursal_id',
    'jornada_id',
    'cliente_id',
    'saldo_anterior_javas',
    'saldo_nuevo_javas',
    'diferencia_javas',
    'saldo_anterior_bandejas',
    'saldo_nuevo_bandejas',
    'diferencia_bandejas',
    'motivo',
    'created_by',
])]
class AjusteSaldoJava extends Model
{
    protected $table = 'ajustes_saldos_javas';

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(JornadaOperativa::class, 'jornada_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'cliente_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'saldo_anterior_javas' => 'integer',
            'saldo_nuevo_javas' => 'integer',
            'diferencia_javas' => 'integer',
            'saldo_anterior_bandejas' => 'integer',
            'saldo_nuevo_bandejas' => 'integer',
            'diferencia_bandejas' => 'integer',
        ];
    }
}
