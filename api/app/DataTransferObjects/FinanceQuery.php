<?php

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;

class FinanceQuery
{
    public function __construct(
        public readonly string $tenantId,
        public readonly CarbonImmutable $rangeStartUtc,
        public readonly CarbonImmutable $rangeEndUtc,
        public readonly CarbonImmutable $rangeStartLocal,
        public readonly CarbonImmutable $rangeEndLocal,
        public readonly string $timezone,
        public readonly ?string $storeId,
        public readonly string $currency,
        public readonly int $limit,
        public readonly string $bucket
    ) {
    }

    public function cacheFragment(): string
    {
        $payload = [
            'tenant' => $this->tenantId,
            'from' => $this->rangeStartUtc->toIso8601String(),
            'to' => $this->rangeEndUtc->toIso8601String(),
            'store' => $this->storeId ?? 'all',
            'currency' => $this->currency,
            'limit' => $this->limit,
            'bucket' => $this->bucket,
        ];

        return sha1(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function sqlRange(): array
    {
        return [
            $this->rangeStartUtc->toDateTimeString(),
            $this->rangeEndUtc->toDateTimeString(),
        ];
    }

    public function limit(int $max = 25): int
    {
        return min($this->limit, $max);
    }

    public function withRange(CarbonImmutable $startLocal, CarbonImmutable $endLocal): self
    {
        $startUtc = $startLocal->setTimezone('UTC');
        $endUtc = $endLocal->setTimezone('UTC');

        return new self(
            tenantId: $this->tenantId,
            rangeStartUtc: $startUtc,
            rangeEndUtc: $endUtc,
            rangeStartLocal: $startLocal,
            rangeEndLocal: $endLocal,
            timezone: $this->timezone,
            storeId: $this->storeId,
            currency: $this->currency,
            limit: $this->limit,
            bucket: $this->bucket
        );
    }
}
