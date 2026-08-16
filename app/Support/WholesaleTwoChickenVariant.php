<?php

namespace App\Support;

use App\Models\Pesada;
use App\Models\TipoPollo;

final class WholesaleTwoChickenVariant
{
    public const MALE = 'MACHO';

    public const FEMALE = 'HEMBRA';

    public const MALE_OPEN = 'MACHO_ABIERTO';

    public const MALE_CLOSED = 'MACHO_CERRADO';

    public const FEMALE_OPEN = 'HEMBRA_ABIERTA';

    public const FEMALE_CLOSED = 'HEMBRA_CERRADA';

    public const PROCESSED = 'POLLO_BENEFICIADO';

    public const HEN_RED = 'GALLINA_ROJA';

    public const HEN_DOUBLE = 'GALLINA_DOBLE';

    public const OTHER = 'OTROS';

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array{chicken_type_code: string, sex: ?string, presentation: ?string}|null
     */
    public static function definition(?string $code): ?array
    {
        return self::definitions()[$code ?? ''] ?? null;
    }

    public static function fromStored(
        ?string $chickenTypeCode,
        ?string $sex,
        ?string $presentation,
    ): ?string {
        foreach (self::definitions() as $code => $definition) {
            if (
                $definition['chicken_type_code'] === $chickenTypeCode
                && $definition['sex'] === $sex
                && $definition['presentation'] === $presentation
            ) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{chicken_type_code: string, sex: ?string, presentation: ?string}>
     */
    private static function definitions(): array
    {
        return [
            self::MALE => [
                'chicken_type_code' => TipoPollo::CHICKEN_LIVE,
                'sex' => Pesada::SEX_MALE,
                'presentation' => null,
            ],
            self::FEMALE => [
                'chicken_type_code' => TipoPollo::CHICKEN_LIVE,
                'sex' => Pesada::SEX_FEMALE,
                'presentation' => null,
            ],
            self::MALE_OPEN => [
                'chicken_type_code' => TipoPollo::CHICKEN_DRESSED,
                'sex' => Pesada::SEX_MALE,
                'presentation' => 'ABIERTO',
            ],
            self::MALE_CLOSED => [
                'chicken_type_code' => TipoPollo::CHICKEN_DRESSED,
                'sex' => Pesada::SEX_MALE,
                'presentation' => 'CERRADO',
            ],
            self::FEMALE_OPEN => [
                'chicken_type_code' => TipoPollo::CHICKEN_DRESSED,
                'sex' => Pesada::SEX_FEMALE,
                'presentation' => 'ABIERTA',
            ],
            self::FEMALE_CLOSED => [
                'chicken_type_code' => TipoPollo::CHICKEN_DRESSED,
                'sex' => Pesada::SEX_FEMALE,
                'presentation' => 'CERRADA',
            ],
            self::PROCESSED => [
                'chicken_type_code' => TipoPollo::CHICKEN_PROCESSED,
                'sex' => null,
                'presentation' => null,
            ],
            self::HEN_RED => [
                'chicken_type_code' => TipoPollo::HEN_RED,
                'sex' => null,
                'presentation' => null,
            ],
            self::HEN_DOUBLE => [
                'chicken_type_code' => TipoPollo::HEN_DOUBLE,
                'sex' => null,
                'presentation' => null,
            ],
            self::OTHER => [
                'chicken_type_code' => TipoPollo::OTHER,
                'sex' => null,
                'presentation' => null,
            ],
        ];
    }
}
