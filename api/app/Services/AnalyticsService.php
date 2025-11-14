<?php

namespace App\Services;

use App\DataTransferObjects\AnalyticsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager as DB;
use Illuminate\Database\Query\Builder;

class AnalyticsService
{
    private const CACHE_TTL = 60;

    public function __construct(
        private DB $db,
        private CacheRepository $cache
    ) {
    }

    public function summary(AnalyticsQuery $filters): array
    {
        return $this->remember('summary', $filters, function () use ($filters) {
            return [
                'range' => $filters->rangeArray(),
                'filters' => $filters->filtersArray(),
                'kpis' => $this->kpis($filters),
                'trend_daily' => $this->trendDaily($filters),
                'tender_mix' => $this->tenderMix($filters),
                'top_products' => $this->topProducts($filters),
                'top_categories' => $this->topCategories($filters),
            ];
        });
    }

    public function hourlyHeatmap(AnalyticsQuery $filters): array
    {
        return $this->remember('hourly-heatmap', $filters, function () use ($filters) {
            $rows = $this->ordersBaseQuery($filters)
                ->selectRaw("EXTRACT(DOW FROM timezone(?, o.created_at)) as dow", [$filters->timezone])
                ->selectRaw("EXTRACT(HOUR FROM timezone(?, o.created_at)) as hour", [$filters->timezone])
                ->selectRaw('COUNT(*) as orders')
                ->selectRaw('COALESCE(SUM(o.total), 0) as revenue_gross')
                ->groupBy('dow', 'hour')
                ->orderBy('dow')
                ->orderBy('hour')
                ->get();

            return $rows->map(function ($row) {
                return [
                    'dow' => (int) $row->dow,
                    'hour' => (int) $row->hour,
                    'orders' => (int) $row->orders,
                    'revenue_gross' => (float) $row->revenue_gross,
                ];
            })->all();
        });
    }

    public function cashiers(AnalyticsQuery $filters): array
    {
        return $this->remember('cashiers', $filters, function () use ($filters) {
            $rows = $this->ordersBaseQuery($filters)
                ->join('users as u', 'u.id', '=', 'o.cashier_id')
                ->select('u.id as user_id', 'u.name')
                ->selectRaw('COUNT(*) as orders')
                ->selectRaw('COALESCE(SUM(o.total), 0) as revenue_gross')
                ->selectRaw('COALESCE(AVG(EXTRACT(EPOCH FROM (o.updated_at - o.created_at))), 0) as handle_seconds')
                ->groupBy('u.id', 'u.name')
                ->orderByDesc('orders')
                ->limit($filters->limit(25))
                ->get();

            return $rows->map(function ($row) {
                return [
                    'user_id' => $row->user_id,
                    'name' => $row->name,
                    'orders' => (int) $row->orders,
                    'revenue_gross' => (float) $row->revenue_gross,
                    'avg_handle_time_seconds' => (float) $row->handle_seconds,
                ];
            })->all();
        });
    }

    public function refunds(AnalyticsQuery $filters): array
    {
        return $this->remember('refunds', $filters, function () use ($filters) {
            $rows = $this->refundsBaseQuery($filters)
                ->selectRaw("DATE(timezone(?, r.created_at)) as date", [$filters->timezone])
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('COALESCE(SUM(r.amount), 0) as amount')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return $rows->map(function ($row) {
                return [
                    'date' => $row->date,
                    'count' => (int) $row->count,
                    'amount' => (float) $row->amount,
                ];
            })->all();
        });
    }

    public function streamOrdersCsv(AnalyticsQuery $filters, $handle = null): void
    {
        $handle ??= fopen('php://output', 'w');

        fputcsv($handle, [
            'order_id',
            'date',
            'store_id',
            'subtotal',
            'tax',
            'discount',
            'total',
            'tender',
            'items_count',
            'customer_id',
        ]);

        $this->ordersBaseQuery($filters)
            ->select('o.id', 'o.created_at', 'o.store_id', 'o.subtotal', 'o.tax', 'o.discount', 'o.manual_discount', 'o.total', 'o.payment_method')
            ->selectSub(function (Builder $sub) {
                $sub->from('payments as p')
                    ->select('p.method')
                    ->whereColumn('p.order_id', 'o.id')
                    ->where('p.status', 'captured')
                    ->orderByDesc('p.captured_at')
                    ->limit(1);
            }, 'tender')
            ->selectSub(function (Builder $sub) {
                $sub->from('order_items as oi')
                    ->selectRaw('COALESCE(SUM(oi.qty), 0)')
                    ->whereColumn('oi.order_id', 'o.id');
            }, 'items_count')
            ->selectSub(function (Builder $sub) {
                $sub->from('customer_orders as co')
                    ->select('co.customer_id')
                    ->whereColumn('co.order_id', 'o.id')
                    ->orderBy('co.created_at')
                    ->limit(1);
            }, 'customer_id')
            ->orderBy('o.created_at')
            ->chunk(250, function ($rows) use ($handle, $filters) {
                foreach ($rows as $row) {
                    $created = CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone($filters->timezone);
                    $discountTotal = (float) $row->discount + (float) $row->manual_discount;

                    fputcsv($handle, [
                        $row->id,
                        $created->toDateTimeString(),
                        $row->store_id,
                        (float) $row->subtotal,
                        (float) $row->tax,
                        $discountTotal,
                        (float) $row->total,
                        $row->tender ?? $row->payment_method ?? null,
                        (float) $row->items_count,
                        $row->customer_id,
                    ]);
                }
            });
    }

    protected function kpis(AnalyticsQuery $filters): array
    {
        $ordersQuery = $this->ordersBaseQuery($filters);

        $kpis = $ordersQuery
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(o.total), 0) as revenue_gross')
            ->selectRaw('COALESCE(SUM(o.discount + o.manual_discount), 0) as discounts_amount')
            ->selectRaw('COALESCE(SUM(o.tax), 0) as taxes_collected')
            ->first();

        $ordersCount = (int) ($kpis->orders_count ?? 0);
        $revenueGross = (float) ($kpis->revenue_gross ?? 0);

        $itemsRow = $this->orderItemsBaseQuery($filters)
            ->selectRaw('COALESCE(SUM(oi.qty), 0) as qty_total')
            ->first();

        $marginRow = $this->orderItemsBaseQuery($filters)
            ->selectRaw('COALESCE(SUM( (oi.unit_price * oi.qty) - oi.cogs_amount ), 0) as margin')
            ->first();

        $uniqueCustomers = $this->db->table('customer_orders as co')
            ->join('orders as o', 'o.id', '=', 'co.order_id')
            ->where('o.tenant_id', $filters->tenantId)
            ->where('o.status', 'paid')
            ->whereBetween('o.created_at', $filters->sqlRange())
            ->when($filters->storeId, function ($query, $storeId) {
                $query->where('o.store_id', $storeId);
            })
            ->distinct('co.customer_id')
            ->count('co.customer_id');

        $refundRow = $this->refundsBaseQuery($filters)
            ->selectRaw('COALESCE(SUM(r.amount), 0) as amount')
            ->first();

        $itemsTotal = (float) ($itemsRow->qty_total ?? 0);
        $refundAmount = (float) ($refundRow->amount ?? 0);
        $margin = (float) ($marginRow->margin ?? 0);

        return [
            'revenue_gross' => $revenueGross,
            'orders' => $ordersCount,
            'aov' => $ordersCount > 0 ? $revenueGross / $ordersCount : 0.0,
            'items_per_order' => $ordersCount > 0 ? $itemsTotal / $ordersCount : 0.0,
            'refunds_amount' => $refundAmount,
            'discounts_amount' => (float) ($kpis->discounts_amount ?? 0),
            'taxes_collected' => (float) ($kpis->taxes_collected ?? 0),
            'gross_margin_estimate' => $margin,
            'unique_customers' => (int) $uniqueCustomers,
        ];
    }

    protected function trendDaily(AnalyticsQuery $filters): array
    {
        $orders = $this->ordersBaseQuery($filters)
            ->selectRaw("DATE(timezone(?, o.created_at)) as day", [$filters->timezone])
            ->selectRaw('COALESCE(SUM(o.total), 0) as revenue_gross')
            ->selectRaw('COUNT(*) as orders')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $refunds = $this->refundsBaseQuery($filters)
            ->selectRaw("DATE(timezone(?, r.created_at)) as day", [$filters->timezone])
            ->selectRaw('COALESCE(SUM(r.amount), 0) as refunds_amount')
            ->groupBy('day')
            ->pluck('refunds_amount', 'day');

        return $orders->map(function ($row) use ($refunds) {
            $day = $row->day;
            $refundTotal = isset($refunds[$day]) ? (float) $refunds[$day] : 0.0;

            return [
                'date' => $day,
                'revenue_gross' => (float) $row->revenue_gross,
                'orders' => (int) $row->orders,
                'refunds_amount' => $refundTotal,
            ];
        })->all();
    }

    protected function tenderMix(AnalyticsQuery $filters): array
    {
        $rows = $this->paymentsBaseQuery($filters)
            ->select('p.method as tender')
            ->selectRaw('COALESCE(SUM(p.amount), 0) as amount')
            ->groupBy('p.method')
            ->orderByDesc('amount')
            ->get();

        return $rows->map(function ($row) {
            return [
                'tender' => $row->tender,
                'amount' => (float) $row->amount,
            ];
        })->all();
    }

    protected function topProducts(AnalyticsQuery $filters): array
    {
        $rows = $this->orderItemsBaseQuery($filters)
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->select('oi.product_id')
            ->selectRaw('COALESCE(p.title, ?) as title', ['Untitled Product'])
            ->selectRaw('COALESCE(SUM(oi.qty), 0) as qty')
            ->selectRaw('COALESCE(SUM(oi.qty * oi.unit_price), 0) as revenue')
            ->groupBy('oi.product_id', 'p.title')
            ->orderByDesc('qty')
            ->limit($filters->limit())
            ->get();

        return $rows->map(function ($row) {
            return [
                'product_id' => $row->product_id,
                'title' => $row->title,
                'qty' => (float) $row->qty,
                'revenue' => (float) $row->revenue,
            ];
        })->all();
    }

    protected function topCategories(AnalyticsQuery $filters): array
    {
        $rows = $this->orderItemsBaseQuery($filters)
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('menu_categories as mc', 'mc.id', '=', 'p.menu_category_id')
            ->select('p.menu_category_id')
            ->selectRaw('COALESCE(mc.name, ?) as name', ['Uncategorized'])
            ->selectRaw('COALESCE(SUM(oi.qty), 0) as qty')
            ->selectRaw('COALESCE(SUM(oi.qty * oi.unit_price), 0) as revenue')
            ->groupBy('p.menu_category_id', 'mc.name')
            ->orderByDesc('qty')
            ->limit($filters->limit())
            ->get();

        return $rows->map(function ($row) {
            return [
                'menu_category_id' => $row->menu_category_id,
                'name' => $row->name,
                'qty' => (float) $row->qty,
                'revenue' => (float) $row->revenue,
            ];
        })->all();
    }

    protected function remember(string $prefix, AnalyticsQuery $filters, callable $callback): mixed
    {
        $key = implode(':', ['analytics', $prefix, $filters->cacheFragment()]);
        $tenantTag = "tenant:{$filters->tenantId}";

        $store = $this->cache->getStore();
        $supportsTags = method_exists($store, 'supportsTags') && $store->supportsTags();

        if ($supportsTags) {
            return $this->cache
                ->tags([$tenantTag, 'analytics'])
                ->remember($key, self::CACHE_TTL, $callback);
        }

        $fallbackKey = implode(':', ['analytics', $tenantTag, $key]);

        return $this->cache->remember($fallbackKey, self::CACHE_TTL, $callback);
    }

    protected function ordersBaseQuery(AnalyticsQuery $filters): Builder
    {
        return $this->db->table('orders as o')
            ->where('o.tenant_id', $filters->tenantId)
            ->where('o.status', 'paid')
            ->whereBetween('o.created_at', $filters->sqlRange())
            ->when($filters->storeId, function ($query, $storeId) {
                $query->where('o.store_id', $storeId);
            });
    }

    protected function orderItemsBaseQuery(AnalyticsQuery $filters): Builder
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

    protected function refundsBaseQuery(AnalyticsQuery $filters): Builder
    {
        return $this->db->table('refunds as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.tenant_id', $filters->tenantId)
            ->whereBetween('r.created_at', $filters->sqlRange())
            ->when($filters->storeId, function ($query, $storeId) {
                $query->where('o.store_id', $storeId);
            });
    }

    protected function paymentsBaseQuery(AnalyticsQuery $filters): Builder
    {
        return $this->db->table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.tenant_id', $filters->tenantId)
            ->where('p.status', 'captured')
            ->whereBetween('o.created_at', $filters->sqlRange())
            ->when($filters->storeId, function ($query, $storeId) {
                $query->where('o.store_id', $storeId);
            });
    }
}
