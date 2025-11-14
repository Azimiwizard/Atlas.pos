<?php

namespace App\Support\Finance;

use Carbon\CarbonInterface;

if (!function_exists(__NAMESPACE__.'\\bucketByPeriod')) {
    /**
     * @param CarbonInterface $timestamp
     */
    function bucketByPeriod(CarbonInterface $timestamp, string $granularity, string $timezone): string
    {
        $unit = strtolower($granularity);

        if (!in_array($unit, ['day', 'week', 'month'], true)) {
            $unit = 'day';
        }

        $localized = $timestamp->setTimezone($timezone);

        return match ($unit) {
            'week' => sprintf(
                '%d-W%s',
                $localized->isoWeekYear(),
                str_pad((string) $localized->isoWeek(), 2, '0', STR_PAD_LEFT)
            ),
            'month' => $localized->format('Y-m'),
            default => $localized->toDateString(),
        };
    }
}
