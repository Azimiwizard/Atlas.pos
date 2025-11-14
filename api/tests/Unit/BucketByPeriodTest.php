<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Tests\TestCase;
use function App\Support\Finance\bucketByPeriod;

class BucketByPeriodTest extends TestCase
{
    public function test_day_bucket_respects_timezone_across_dst(): void
    {
        $timestamp = CarbonImmutable::parse('2025-11-02 05:30:00', 'UTC');

        $bucket = bucketByPeriod($timestamp, 'day', 'America/New_York');

        $this->assertSame('2025-11-02', $bucket);
    }

    public function test_week_bucket_uses_iso_week_year(): void
    {
        $timestamp = CarbonImmutable::parse('2025-01-03 12:00:00', 'UTC');

        $bucket = bucketByPeriod($timestamp, 'week', 'UTC');

        $this->assertSame('2025-W01', $bucket);
    }

    public function test_month_bucket_formats_year_month(): void
    {
        $timestamp = CarbonImmutable::parse('2025-08-15 00:00:00', 'UTC');

        $bucket = bucketByPeriod($timestamp, 'month', 'Africa/Casablanca');

        $this->assertSame('2025-08', $bucket);
    }
}
