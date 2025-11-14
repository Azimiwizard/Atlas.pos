<?php

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;

class AnalyticsQuery
{
    public function __construct(
        public readonly string $tenantId,
        public readonly CarbonImmutable $rangeStartUtc,
        public readonly CarbonImmutable $rangeEndUtc,
        public readonly CarbonImmutable $rangeStartLocal,
        public readonly CarbonImmutable $rangeEndLocal,
        public readonly string $timezone,
        public readonly ?string $storeId,
        public readonly int $limit
    ) {
    }

    public function cacheFragment(): string
    {
        $payload = [
            'tenant' => $this->tenantId,
            'from' => $this->rangeStartUtc->toIso8601String(),
            'to' => $this->rangeEndUtc->toIso8601String(),
            'store' => $this->storeId ?? 'all',
            'tz' => $this->timezone,
            'limit' => $this->limit,
        ];

        return sha1(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function limit(int $max = 50): int
    {
        return min($this->limit, $max);
    }

    public function rangeArray(): array
    {
        return [
            'from' => $this->rangeStartLocal->toDateString(),
            'to' => $this->rangeEndLocal->toDateString(),
            'tz' => $this->timezone,
        ];
    }

    public function filtersArray(): array
    {
        return [
            'store_id' => $this->storeId,
        ];
    }

    public function sqlRange(): array
    {
        return [
            $this->rangeStartUtc->toDateTimeString(),
            $this->rangeEndUtc->toDateTimeString(),
        ];
    }
}
