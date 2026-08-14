<?php

namespace App\Models;

use App\Support\WholesaleTwoChickenVariant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'codigo',
    'nombre',
    'sexo',
    'presentacion',
    'gramos_adicionales',
    'estado',
])]
class AjustePesoMayoristaDos extends Model
{
    public const STATUS_ACTIVE = 'ACTIVO';

    protected $table = 'ajustes_peso_mayorista_2';

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }

    /** @return list<string> */
    public static function configurableCodes(): array
    {
        return collect(self::definitions())
            ->filter(fn (array $definition): bool => $definition['configurable'])
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{name: string, sex: ?string, presentation: ?string, configurable: bool}>
     */
    public static function definitions(): array
    {
        return [
            WholesaleTwoChickenVariant::MALE => [
                'name' => 'Macho vivo',
                'sex' => Pesada::SEX_MALE,
                'presentation' => null,
                'configurable' => true,
            ],
            WholesaleTwoChickenVariant::FEMALE => [
                'name' => 'Hembra viva',
                'sex' => Pesada::SEX_FEMALE,
                'presentation' => null,
                'configurable' => true,
            ],
            WholesaleTwoChickenVariant::MALE_OPEN => [
                'name' => 'Macho abierto',
                'sex' => Pesada::SEX_MALE,
                'presentation' => 'ABIERTO',
                'configurable' => true,
            ],
            WholesaleTwoChickenVariant::MALE_CLOSED => [
                'name' => 'Macho cerrado',
                'sex' => Pesada::SEX_MALE,
                'presentation' => 'CERRADO',
                'configurable' => true,
            ],
            WholesaleTwoChickenVariant::FEMALE_OPEN => [
                'name' => 'Hembra abierta',
                'sex' => Pesada::SEX_FEMALE,
                'presentation' => 'ABIERTA',
                'configurable' => true,
            ],
            WholesaleTwoChickenVariant::FEMALE_CLOSED => [
                'name' => 'Hembra cerrada',
                'sex' => Pesada::SEX_FEMALE,
                'presentation' => 'CERRADA',
                'configurable' => true,
            ],
            WholesaleTwoChickenVariant::PROCESSED => [
                'name' => 'Pollo beneficiado',
                'sex' => null,
                'presentation' => null,
                'configurable' => false,
            ],
        ];
    }

    public function isConfigurable(): bool
    {
        return (bool) (self::definitions()[$this->codigo]['configurable'] ?? false);
    }

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /** @return HasMany<Pesada, $this> */
    public function pesadas(): HasMany
    {
        return $this->hasMany(Pesada::class, 'ajuste_peso_mayorista_2_id');
    }

    protected function casts(): array
    {
        return [
            'gramos_adicionales' => 'integer',
        ];
    }
}
