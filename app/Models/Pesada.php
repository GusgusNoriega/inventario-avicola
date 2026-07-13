<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'ticket_id',
    'numero',
    'tipo_pollo_id',
    'condicion_pollo',
    'sexo',
    'presentacion_pollo',
    'tipo_java_id',
    'tipo_bandeja_id',
    'ajuste_peso_minorista_id',
    'lectura_balanza_id',
    'proveedor_origen_id',
    'almacen_origen_id',
    'vehiculo_id',
    'programacion_recepcion_detalle_id',
    'placa_snapshot',
    'origen_peso',
    'aves_por_java',
    'aves_por_bandeja',
    'cantidad_javas',
    'cantidad_bandejas',
    'cantidad_aves',
    'peso_java_kg_snapshot',
    'peso_bandeja_kg_snapshot',
    'peso_leido_kg',
    'ajuste_peso_gramos',
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
class Pesada extends Model
{
    public const CHICKEN_CONDITION_LIVE = 'VIVO';

    public const CHICKEN_CONDITION_DEAD = 'MUERTO';

    public const SEX_MALE = 'MACHO';

    public const SEX_FEMALE = 'HEMBRA';

    public const STATUS_ACTIVE = 'ACTIVA';

    public const STATUS_VOIDED = 'ANULADA';

    protected $table = 'pesadas';

    /**
     * @return BelongsTo<TicketDespacho, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketDespacho::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<TipoPollo, $this>
     */
    public function tipoPollo(): BelongsTo
    {
        return $this->belongsTo(TipoPollo::class);
    }

    /**
     * @return BelongsTo<TipoJava, $this>
     */
    public function tipoJava(): BelongsTo
    {
        return $this->belongsTo(TipoJava::class);
    }

    /**
     * @return BelongsTo<TipoBandeja, $this>
     */
    public function tipoBandeja(): BelongsTo
    {
        return $this->belongsTo(TipoBandeja::class);
    }

    /**
     * @return BelongsTo<AjustePesoMinorista, $this>
     */
    public function ajustePesoMinorista(): BelongsTo
    {
        return $this->belongsTo(AjustePesoMinorista::class, 'ajuste_peso_minorista_id');
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function proveedorOrigen(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'proveedor_origen_id');
    }

    /**
     * @return BelongsTo<Almacen, $this>
     */
    public function almacenOrigen(): BelongsTo
    {
        return $this->belongsTo(Almacen::class, 'almacen_origen_id');
    }

    /**
     * @return BelongsTo<Vehiculo, $this>
     */
    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    /**
     * @return HasOne<CostoCompraPesada, $this>
     */
    public function costoCompra(): HasOne
    {
        return $this->hasOne(CostoCompraPesada::class);
    }

    /**
     * @return BelongsToMany<Comprobante, $this>
     */
    public function comprobantes(): BelongsToMany
    {
        return $this->belongsToMany(Comprobante::class, 'comprobante_pesadas', 'pesada_id', 'comprobante_id')
            ->withPivot('importe_aplicado');
    }

    protected function casts(): array
    {
        return [
            'peso_java_kg_snapshot' => 'decimal:3',
            'peso_bandeja_kg_snapshot' => 'decimal:3',
            'peso_leido_kg' => 'decimal:3',
            'ajuste_peso_gramos' => 'integer',
            'peso_bruto_kg' => 'decimal:3',
            'tara_total_kg' => 'decimal:3',
            'peso_neto_kg' => 'decimal:3',
            'pesada_at' => 'datetime',
            'anulada_at' => 'datetime',
        ];
    }
}
