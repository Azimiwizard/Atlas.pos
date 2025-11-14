<?php

namespace App\Services;

use App\DataTransferObjects\FinanceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use function App\Support\Finance\bucketByPeriod;

class FinanceService
{
    private const CACHE_TTL = 60;
    private const HEALTH_WEEK_DAYS = 7;
    private const HEALTH_MONTH_DAYS = 30;
    private const HEALTH_EXPENSE_BASELINE_MONTHS = 3;

    public function __construct(
        private DB $db,
        private CacheRepository $cache
    ) {
    }

    public function summary(FinanceQuery $filters): array
    {
        return $this->remember('summary', $filters, function () use ($filters) {
            $ordersRow = $this->ordersBaseQuery($filters)
                ->selectRaw('COUNT(*) as orders_count')
                ->selectRaw('COALESCE(SUM(o.total), 0) as revenue')
                ->first();

            $ordersCount = (int) ($ordersRow->orders_count ?? 0);
            $revenue = (float) ($ordersRow->revenue ?? 0);
            $avgTicket = $ordersCount > 0 ? $revenue / $ordersCount : 0.0;

            $cogs = (float) ($this->orderItemsBaseQuery($filters)
                ->selectRaw('COALESCE(SUM(oi.cogs_amount), 0) as cogs')
                ->value('cogs') ?? 0);

            $grossProfit = $revenue - $cogs;
            $grossMargin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;

            $expensesTotal = $this->expenseSum($filters);

            $netProfit = $grossProfit - $expensesTotal;
            $netMargin = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;

            return [
                'revenue' => round($revenue, 2),
                'cogs' => round($cogs, 2),
                'gross_profit' => round($grossProfit, 2),
                'gross_margin' => round($grossMargin, 2),
                'expenses_total' => round($expensesTotal, 2),
                'net_profit' => round($netProfit, 2),
                'net_margin' => round($netMargin, 2),
                'avg_ticket' => round($avgTicket, 2),
                'orders_count' => $ordersCount,
            ];
        });
    }

    public function flow(FinanceQuery $filters): array
    {
        return $this->remember('flow', $filters, function () use ($filters) {
            $bucket = $this->normalizeBucket($filters->bucket);

            $orders = (clone $this->ordersBaseQuery($filters))
                ->select('o.id', 'o.total', 'o.created_at')
                ->orderBy('o.created_at')
                ->get();

            $cogsByOrder = (clone $this->orderItemsBaseQuery($filters))
                ->select('oi.order_id')
                ->selectRaw('COALESCE(SUM(oi.cogs_amount), 0) as cogs')
                ->groupBy('oi.order_id')
                ->pluck('cogs', 'order_id');

            $aggregates = [];

            foreach ($orders as $order) {
                $createdAt = CarbonImmutable::parse($order->created_at, 'UTC');
                $bucketKey = bucketByPeriod($createdAt, $bucket, $filters->timezone);

                if (!isset($aggregates[$bucketKey])) {
                    $aggregates[$bucketKey] = ['inflow' => 0.0, 'outflow' => 0.0];
                }

                $aggregates[$bucketKey]['inflow'] += (float) $order->total;
                $aggregates[$bucketKey]['outflow'] += (float) ($cogsByOrder[$order->id] ?? 0);
            }

            $timeline = $this->buildBucketScaffold($filters, $bucket);

            foreach ($timeline as $bucketKey => &$row) {
                if (isset($aggregates[$bucketKey])) {
                    $row['cash_in'] = round($aggregates[$bucketKey]['inflow'], 2);
                    $row['cash_out'] = round($aggregates[$bucketKey]['outflow'], 2);
                }
                $row['net'] = round($row['cash_in'] - $row['cash_out'], 2);
                $row['profit'] = $row['net'];
            }

            return array_values($timeline);
        });
    }

    public function expenses(FinanceQuery $filters): array
    {
        return $this->remember('expenses', $filters, function () use ($filters) {
            $rows = $this->orderItemsBaseQuery($filters)
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->leftJoin('menu_categories as mc', 'mc.id', '=', 'p.menu_category_id')
                ->selectRaw("COALESCE(mc.name, 'Uncategorized') as category")
                ->selectRaw('COALESCE(SUM(oi.cogs_amount), 0) as amount')
                ->groupByRaw("COALESCE(mc.name, 'Uncategorized')")
                ->orderByDesc('amount')
                ->limit($filters->limit(12))
                ->get();

            $total = (float) $rows->sum('amount');
            if ($total <= 0) {
                return [];
            }

            return $rows->map(function ($row) use ($total) {
                $amount = (float) $row->amount;
                return [
                    'category' => $row->category,
                    'amount' => $amount,
                    'percent' => ($amount / $total) * 100,
                ];
            })->all();
        });
    }

    public function health(FinanceQuery $filters): array
    {
        return $this->remember('health', $filters, function () use ($filters) {
            $summary = $this->summary($filters);
            $netMargin = $summary['net_margin'];
            $grossMargin = $summary['gross_margin'];

            $score = (int) round(
                max(
                    0,
                    min(100, ($grossMargin * 0.4) + ($netMargin * 0.6))
                )
            );
            $signals = $this->buildHealthSignals($filters, $summary);

            return [
                'score' => $score,
                'signals' => $signals,
            ];
        });
    }
    public function exportDataset(FinanceQuery $filters): array
    {
        return [
            'summary' => $this->summary($filters),
            'flow' => $this->flow($filters),
            'expenses' => $this->expenses($filters),
            'health' => $this->health($filters),
            'filters' => [
                'from' => $filters->rangeStartLocal->toDateString(),
                'to' => $filters->rangeEndLocal->toDateString(),
                'currency' => $filters->currency,
                'timezone' => $filters->timezone,
                'store' => $filters->storeId ?? 'All Stores',
                'bucket' => $filters->bucket,
            ],
        ];
    }

    private function buildHealthSignals(FinanceQuery $filters, array $summary): array
    {
        $signals = [];
        $summaryCache = [];

        if ($signal = $this->revenueDropWoWSignal($filters, $summaryCache)) {
            $signals[] = $signal;
        }

        if ($signal = $this->grossMarginDropMoMSignal($filters, $summaryCache)) {
            $signals[] = $signal;
        }

        foreach ($this->expenseSpikeSignals($filters) as $expenseSignal) {
            $signals[] = $expenseSignal;
        }

        if ($signal = $this->negativeNetRunSignal($filters, $summaryCache)) {
            $signals[] = $signal;
        }

        if (empty($signals)) {
            $signals[] = [
                'label' => 'Steady performance',
                'level' => 'info',
                'detail' => sprintf(
                    'Revenue %s, net margin %s%%, and expenses %s are within expected bands.',
                    $this->formatCurrency($summary['revenue'], $filters->currency),
                    number_format($summary['net_margin'], 1, '.', ''),
                    $this->formatCurrency($summary['expenses_total'], $filters->currency)
                ),
                'period' => 'rolling',
            ];
        }

        return $signals;
    }

    private function revenueDropWoWSignal(FinanceQuery $filters, array &$summaryCache): ?array
    {
        $currentRange = $this->windowWithinRange($filters, self::HEALTH_WEEK_DAYS, 0);
        $previousRange = $this->windowWithinRange($filters, self::HEALTH_WEEK_DAYS, self::HEALTH_WEEK_DAYS);

        if (!$currentRange || !$previousRange) {
            return null;
        }

        [$currentStart, $currentEnd] = $currentRange;
        [$previousStart, $previousEnd] = $previousRange;

        $current = $this->summaryForRange($filters, $currentStart, $currentEnd, $summaryCache);
        $previous = $this->summaryForRange($filters, $previousStart, $previousEnd, $summaryCache);

        $previousRevenue = $previous['revenue'];
        $currentRevenue = $current['revenue'];

        if ($previousRevenue <= 0) {
            return null;
        }

        $dropPercent = (($previousRevenue - $currentRevenue) / $previousRevenue) * 100;

        if ($dropPercent < 10) {
            return null;
        }

        $level = $dropPercent >= 20 ? 'alert' : 'warn';

        return [
            'label' => 'Revenue ↓ ≥10% WoW',
            'level' => $level,
            'detail' => sprintf(
                'Revenue down %s vs prior week (%s → %s).',
                $this->formatPercent($dropPercent),
                $this->formatCurrency($previousRevenue, $filters->currency),
                $this->formatCurrency($currentRevenue, $filters->currency)
            ),
            'period' => 'week',
        ];
    }

    private function grossMarginDropMoMSignal(FinanceQuery $filters, array &$summaryCache): ?array
    {
        $currentRange = $this->windowWithinRange($filters, self::HEALTH_MONTH_DAYS, 0);
        $previousRange = $this->windowWithinRange($filters, self::HEALTH_MONTH_DAYS, self::HEALTH_MONTH_DAYS);

        if (!$currentRange || !$previousRange) {
            return null;
        }

        [$currentStart, $currentEnd] = $currentRange;
        [$previousStart, $previousEnd] = $previousRange;

        $current = $this->summaryForRange($filters, $currentStart, $currentEnd, $summaryCache);
        $previous = $this->summaryForRange($filters, $previousStart, $previousEnd, $summaryCache);

        $currentMargin = $current['gross_margin'];
        $previousMargin = $previous['gross_margin'];
        $dropPoints = $previousMargin - $currentMargin;

        if ($dropPoints < 3) {
            return null;
        }

        $level = $dropPoints >= 7 ? 'alert' : 'warn';

        return [
            'label' => 'Gross margin ↓ ≥3pp MoM',
            'level' => $level,
            'detail' => sprintf(
                'Gross margin down %spp vs prior month (%s%% → %s%%).',
                number_format($dropPoints, 1, '.', ''),
                number_format($previousMargin, 1, '.', ''),
                number_format($currentMargin, 1, '.', '')
            ),
            'period' => 'month',
        ];
    }

    private function expenseSpikeSignals(FinanceQuery $filters): array
    {
        $currentRange = $this->windowWithinRange($filters, self::HEALTH_MONTH_DAYS, 0);
        $baselineRange = $this->windowWithinRange(
            $filters,
            self::HEALTH_MONTH_DAYS * self::HEALTH_EXPENSE_BASELINE_MONTHS,
            self::HEALTH_MONTH_DAYS
        );

        if (!$currentRange || !$baselineRange) {
            return [];
        }

        [$currentStart, $currentEnd] = $currentRange;
        [$baselineStart, $baselineEnd] = $baselineRange;

        $currentTotals = $this->expensesByCategoryBetween($filters, $currentStart, $currentEnd);
        $baselineTotals = $this->expensesByCategoryBetween($filters, $baselineStart, $baselineEnd);

        if (empty($currentTotals) || empty($baselineTotals)) {
            return [];
        }

        $spikes = [];

        foreach ($currentTotals as $category => $amount) {
            $baselineTotal = $baselineTotals[$category] ?? 0.0;
            $baselineAvg = $baselineTotal > 0
                ? $baselineTotal / self::HEALTH_EXPENSE_BASELINE_MONTHS
                : 0.0;

            if ($baselineAvg <= 0) {
                continue;
            }

            if ($amount >= ($baselineAvg * 2)) {
                $spikes[] = [
                    'category' => $category,
                    'amount' => $amount,
                    'baseline_avg' => $baselineAvg,
                    'ratio' => $amount / $baselineAvg,
                ];
            }
        }

        if (empty($spikes)) {
            return [];
        }

        usort($spikes, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);

        $signals = [];

        foreach (array_slice($spikes, 0, 3) as $spike) {
            $signals[] = [
                'label' => sprintf('Expense spike: %s', $spike['category']),
                'level' => 'alert',
                'detail' => sprintf(
                    '%s spend %s vs 3-mo avg (%s avg → %s now).',
                    $spike['category'],
                    $this->formatMultiplier($spike['ratio']),
                    $this->formatCurrency($spike['baseline_avg'], $filters->currency),
                    $this->formatCurrency($spike['amount'], $filters->currency)
                ),
                'period' => 'month',
            ];
        }

        return $signals;
    }

    private function negativeNetRunSignal(FinanceQuery $filters, array &$summaryCache): ?array
    {
        $currentRange = $this->windowWithinRange($filters, self::HEALTH_MONTH_DAYS, 0);
        $previousRange = $this->windowWithinRange($filters, self::HEALTH_MONTH_DAYS, self::HEALTH_MONTH_DAYS);

        if (!$currentRange || !$previousRange) {
            return null;
        }

        [$currentStart, $currentEnd] = $currentRange;
        [$previousStart, $previousEnd] = $previousRange;

        $current = $this->summaryForRange($filters, $currentStart, $currentEnd, $summaryCache);
        $previous = $this->summaryForRange($filters, $previousStart, $previousEnd, $summaryCache);

        if ($current['net_profit'] >= 0 || $previous['net_profit'] >= 0) {
            return null;
        }

        return [
            'label' => 'Negative net for 2 consecutive months',
            'level' => 'alert',
            'detail' => sprintf(
                'Net losses for back-to-back months (%s, %s).',
                $this->formatCurrency($previous['net_profit'], $filters->currency),
                $this->formatCurrency($current['net_profit'], $filters->currency)
            ),
            'period' => 'month',
        ];
    }

    private function summaryForRange(
        FinanceQuery $filters,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array &$cache
    ): array {
        $key = $start->toIso8601String().'|'.$end->toIso8601String();

        if (!array_key_exists($key, $cache)) {
            $cache[$key] = $this->summary($filters->withRange($start, $end));
        }

        return $cache[$key];
    }

    private function windowWithinRange(
        FinanceQuery $filters,
        int $lengthDays,
        int $offsetDays
    ): ?array {
        $lengthDays = max($lengthDays, 1);
        $offsetDays = max($offsetDays, 0);

        $endBoundary = $filters->rangeEndLocal->endOfDay();
        $startBoundary = $filters->rangeStartLocal->startOfDay();

        $rangeEnd = $endBoundary->subDays($offsetDays);
        $rangeStart = $rangeEnd->subDays($lengthDays - 1)->startOfDay();

        if ($rangeEnd->gt($endBoundary)) {
            return null;
        }

        if ($rangeEnd->lt($startBoundary)) {
            return null;
        }

        if ($rangeStart->lt($startBoundary)) {
            return null;
        }

        return [$rangeStart, $rangeEnd];
    }

    private function expensesByCategoryBetween(
        FinanceQuery $filters,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $builder = $this->db->table('expenses')
            ->selectRaw("COALESCE(category, 'Uncategorized') as category")
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->where('tenant_id', $filters->tenantId)
            ->whereBetween('incurred_at', [
                $start->setTimezone('UTC')->toDateTimeString(),
                $end->setTimezone('UTC')->toDateTimeString(),
            ])
            ->groupByRaw("COALESCE(category, 'Uncategorized')");

        if ($filters->storeId) {
            $builder->where(function ($query) use ($filters) {
                $query->whereNull('store_id')
                    ->orWhere('store_id', $filters->storeId);
            });
        }

        $rows = $builder->get();
        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->category] = (float) $row->total;
        }

        return $totals;
    }

    private function formatCurrency(float $amount, string $currency): string
    {
        return sprintf('%s %s', $currency, number_format($amount, 2, '.', ','));
    }

    private function formatPercent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, '.', '').'%';
    }

    private function formatMultiplier(float $value): string
    {
        return number_format($value, 1, '.', '').'×';
    }

    protected function ordersBaseQuery(FinanceQuery $filters): Builder
    {
        return $this->db->table('orders as o')
            ->where('o.tenant_id', $filters->tenantId)
            ->where('o.status', 'paid')
            ->whereBetween('o.created_at', $filters->sqlRange())
            ->when($filters->storeId, function ($query, $storeId) {
                $query->where('o.store_id', $storeId);
            });
    }

    protected function orderItemsBaseQuery(FinanceQuery $filters): Builder
    {
        return $this->db->table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.tenant_id', $filters->tenantId)
            ->where('o.status', 'paid')
            ->whereBetween('o.created_at', $filters->sqlRange())
            ->when($filters->storeId, function ($query, $storeId) {
                $query->where('o.store_id', $storeId);
            });
    }

    protected function remember(string $prefix, FinanceQuery $filters, callable $callback): mixed
    {
        $key = implode(':', ['finance', $prefix, $filters->cacheFragment()]);
        $tenantTag = "tenant:{$filters->tenantId}";
        $timestampKey = $this->metaCacheKey($prefix, $filters);
        $expiration = now()->addDay();

        $wrappedCallback = function () use ($callback, $timestampKey, $expiration) {
            $value = $callback();
            Cache::put($timestampKey, now(), $expiration);

            return $value;
        };

        $store = $this->cache->getStore();
        $supportsTags = method_exists($store, 'supportsTags') && $store->supportsTags();

        if ($supportsTags) {
            return $this->cache
                ->tags([$tenantTag, 'finance'])
                ->remember($key, self::CACHE_TTL, $wrappedCallback);
        }

        $fallbackKey = implode(':', ['finance', $tenantTag, $key]);

        return $this->cache->remember($fallbackKey, self::CACHE_TTL, $wrappedCallback);
    }

    public function meta(FinanceQuery $filters): array
    {
        $prefixes = ['summary', 'flow', 'expenses', 'health'];
        $meta = [];
        $latest = null;

        foreach ($prefixes as $prefix) {
            $timestamp = Cache::get($this->metaCacheKey($prefix, $filters));

            if ($timestamp) {
                $carbon = $timestamp instanceof \DateTimeInterface
                    ? CarbonImmutable::instance($timestamp)
                    : CarbonImmutable::parse($timestamp);

                $iso = $carbon->toIso8601String();
                $meta[$prefix] = $iso;

                if ($latest === null || $carbon->greaterThan(CarbonImmutable::parse($latest))) {
                    $latest = $iso;
                }
            } else {
                $meta[$prefix] = null;
            }
        }

        $meta['last_updated_at'] = $latest;

        return $meta;
    }

    protected function buildBucketScaffold(FinanceQuery $filters, string $bucket): array
    {
        $scaffold = [];
        $cursor = $filters->rangeStartLocal;

        while ($cursor <= $filters->rangeEndLocal) {
            $label = bucketByPeriod($cursor, $bucket, $filters->timezone);

            if (!array_key_exists($label, $scaffold)) {
                $scaffold[$label] = [
                    'period' => $label,
                    'cash_in' => 0.0,
                    'cash_out' => 0.0,
                    'net' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $cursor = $this->advanceBucketCursor($cursor, $bucket);
        }

        if (empty($scaffold)) {
            $label = bucketByPeriod($filters->rangeStartLocal, $bucket, $filters->timezone);
            $scaffold[$label] = [
                'period' => $label,
                'cash_in' => 0.0,
                'cash_out' => 0.0,
                'net' => 0.0,
                'profit' => 0.0,
            ];
        }

        return $scaffold;
    }

    protected function advanceBucketCursor(CarbonImmutable $cursor, string $bucket): CarbonImmutable
    {
        return match ($bucket) {
            'week' => $cursor->addWeek(),
            'month' => $cursor->addMonth(),
            default => $cursor->addDay(),
        };
    }

    protected function normalizeBucket(string $bucket): string
    {
        $unit = strtolower($bucket);

        return in_array($unit, ['day', 'week', 'month'], true) ? $unit : 'day';
    }

    protected function expenseSum(FinanceQuery $filters): float
    {
        $builder = $this->db->table('expenses')
            ->where('tenant_id', $filters->tenantId)
            ->whereBetween('incurred_at', [
                $filters->rangeStartUtc->setTimezone('UTC')->toDateTimeString(),
                $filters->rangeEndUtc->setTimezone('UTC')->toDateTimeString(),
            ]);

        if ($filters->storeId) {
            $builder->where(function ($query) use ($filters) {
                $query->whereNull('store_id')
                    ->orWhere('store_id', $filters->storeId);
            });
        }

        return (float) ($builder->sum('amount') ?? 0);
    }

    protected function metaCacheKey(string $prefix, FinanceQuery $filters): string
    {
        return implode(':', ['finance', 'last_refresh', $prefix, $filters->cacheFragment()]);
    }
}




