<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class OperatingDate
{
    public static function forTimestamp(CarbonImmutable $localTime, string $cutoff): string
    {
        $endsAt = $localTime->startOfDay()->setTimeFromTimeString($cutoff);

        return ($localTime->greaterThanOrEqualTo($endsAt) ? $localTime->addDay() : $localTime)
            ->toDateString();
    }

    /** @return array{string, string} Inclusive start and exclusive end in storage time. */
    public static function databaseRange(
        string $from,
        string $to,
        string $timezone,
        string $databaseTimezone,
        string $cutoff,
    ): array {
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone)
                ->subDay()->setTimeFromTimeString($cutoff)
                ->setTimezone($databaseTimezone)->toDateTimeString(),
            CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)
                ->setTimeFromTimeString($cutoff)
                ->setTimezone($databaseTimezone)->toDateTimeString(),
        ];
    }
}
