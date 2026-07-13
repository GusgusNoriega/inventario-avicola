<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'codigo',
    'nombre',
    'requiere_referencia',
    'estado',
])]
class MetodoPago extends Model
{
    public const CODE_DEPOSIT = 'DEPOSITO';

    public const CODE_TRANSFER = 'TRANSFERENCIA';

    public const CODE_CASH = 'EFECTIVO';

    public const CODE_YAPE = 'YAPE';

    public const CODE_PLIN = 'PLIN';

    public const CODE_CHECK = 'CHEQUE';

    public const CODE_OTHER = 'OTRO';

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'metodos_pago';

    /**
     * @return HasMany<Pago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    protected function casts(): array
    {
        return [
            'requiere_referencia' => 'boolean',
        ];
    }
}
