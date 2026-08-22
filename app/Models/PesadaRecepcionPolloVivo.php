<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recepcion_id',
    'idempotency_key',
    'numero',
    'columna',
    'propietario_tipo',
    'propietario_externo_id',
    'destino_tipo',
    'almacen_destino_id',
    'cliente_destino_id',
    'sexo',
    'tipo_pollo_id',
    'tipo_java_id',
    'lectura_balanza_id',
    'origen_peso',
    'aves_por_java',
    'cantidad_javas',
    'cantidad_aves',
    'peso_java_kg_snapshot',
    'peso_leido_kg',
    'peso_bruto_kg',
    'tara_total_kg',
    'peso_neto_kg',
    'pesada_at',
    'estado',
    'anulada_por',
    'anulada_at',
    'motivo_anulacion',
    'created_by',
])]
class PesadaRecepcionPolloVivo extends Model
{
    public const OWNER_OWN = 'PROPIA';

    public const OWNER_EXTERNAL = 'EXTERNA';

    public const DESTINATION_WAREHOUSE = 'ALMACEN';

    public const DESTINATION_CLIENT = 'CLIENTE';

    public const STATUS_ACTIVE = 'ACTIVA';

    public const STATUS_VOIDED = 'ANULADA';

    protected $table = 'pesadas_recepcion_pollo_vivo';

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionPolloVivo::class, 'recepcion_id');
    }

    public function propietarioExterno(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'propietario_externo_id');
    }

    public function almacenDestino(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_destino_id');
    }

    public function clienteDestino(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'cliente_destino_id');
    }

    public function tipoJava(): BelongsTo
    {
        return $this->belongsTo(TipoJava::class, 'tipo_java_id');
    }

    protected function casts(): array
    {
        return [
            'columna' => 'integer',
            'aves_por_java' => 'integer',
            'cantidad_javas' => 'integer',
            'cantidad_aves' => 'integer',
            'peso_java_kg_snapshot' => 'decimal:3',
            'peso_leido_kg' => 'decimal:3',
            'peso_bruto_kg' => 'decimal:3',
            'tara_total_kg' => 'decimal:3',
            'peso_neto_kg' => 'decimal:3',
            'pesada_at' => 'datetime',
            'anulada_at' => 'datetime',
        ];
    }
}
