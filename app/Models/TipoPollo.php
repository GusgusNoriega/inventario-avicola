<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['codigo', 'nombre', 'permite_despacho', 'estado'])]
class TipoPollo extends Model
{
    public const CHICKEN_LIVE = 'POLLO_VIVO';

    public const CHICKEN_DRESSED = 'POLLO_PELADO';

    public const CHICKEN_PROCESSED = 'POLLO_BENEFICIADO';

    public const STATUS_ACTIVE = 'ACTIVO';

    protected $table = 'tipos_pollo';

    /**
     * @return HasMany<PrecioHistorial, $this>
     */
    public function precios(): HasMany
    {
        return $this->hasMany(PrecioHistorial::class);
    }
}
