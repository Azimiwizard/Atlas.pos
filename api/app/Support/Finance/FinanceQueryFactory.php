<?php

namespace App\Support\Finance;

use App\DataTransferObjects\FinanceQuery;
use Carbon\CarbonImmutable;

class FinanceQueryFactory
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): FinanceQuery
    {
        $timezone = $payload['timezone'] ?? 'Africa/Casablanca';
        $startLocal = CarbonImmutable::createFromFormat('Y-m-d H:i:s', ($payload['date_from'] ?? now()->toDateString()).' 00:00:00', $timezone);
        $endLocal = CarbonImmutable::createFromFormat('Y-m-d H:i:s', ($payload['date_to'] ?? now()->toDateString()).' 23:59:59', $timezone);

        return new FinanceQuery(
            tenantId: (string) $payload['tenant_id'],
            rangeStartUtc: $startLocal->setTimezone('UTC'),
            rangeEndUtc: $endLocal->setTimezone('UTC'),
            rangeStartLocal: $startLocal,
            rangeEndLocal: $endLocal,
            timezone: $timezone,
            storeId: $payload['store_id'] ?? null,
            currency: $payload['currency'] ?? 'USD',
            limit: (int) ($payload['limit'] ?? 10),
            bucket: $payload['bucket'] ?? 'day'
        );
    }
}
