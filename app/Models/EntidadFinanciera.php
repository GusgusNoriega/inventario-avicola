<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'tipo',
    'proveedor_id',
    'tipo_documento',
    'numero_documento',
    'razon_social',
    'nombre_comercial',
    'direccion',
    'telefono',
    'email',
    'estado',
    'created_by',
])]
class EntidadFinanciera extends Model
{
    public const TYPE_OWN = 'PROPIA';

    public const TYPE_EXTERNAL = 'EXTERNA';

    public const STATUS_ACTIVE = 'ACTIVO';

    public const STATUS_INACTIVE = 'INACTIVO';

    protected $table = 'entidades_financieras';

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Tercero::class, 'proveedor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<CuentaFinanciera, $this>
     */
    public function cuentas(): HasMany
    {
        return $this->hasMany(CuentaFinanciera::class);
    }
}
