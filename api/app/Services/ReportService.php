<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager as DB;
use Illuminate\Support\Carbon;

class ReportService
{
    public function __construct(
        private DB $db,
        private CacheRepository $cache,
        private StoreManager $stores
    ) {
    }

    protected function tenantId(): string
    {
        return (string) auth()->user()->tenant_id;
    }

    protected function storeId(): ?string
    {
        $user = auth()->user();
        if ($user?->role === 'cashier' && $user->store_id) {
            return $user->store_id;
        }

        return $this->stores->id() ?? $user?->store_id;
    }

    protected function applyStoreFilter($query, string $column): void
    {
        $storeId = $this->storeId();
        if ($storeId) {
            $query->where($column, $storeId);
        }
    }

    protected function tagCache(string $key, callable $callback)
    {
        $tenantTag = "tenant:" . $this->tenantId();

        $store = $this->cache->getStore();
        $supportsTags = method_exists($store, 'supportsTags') && $store->supportsTags();

        if ($supportsTags) {
            return $this->cache->tags([$tenantTag, 'reports'])->remember($key, 300, $callback);
        }

        $fallbackKey = implode(':', ['reports', $tenantTag, $key]);

        return $this->cache->remember($fallbackKey, 300, $callback);
    }

    protected function dateExpression(string $column): string
    {
        $driver = $this->db->connection()->getDriverName();

        if ($driver === 'pgsql') {
            return "DATE(timezone('UTC', {$column}))";
        }

        return "DATE(CONVERT_TZ({$column}, '+00:00', '+00:00'))";
    }

    public function salesSummary(string $from, string $to): array
    {
        $tenant = $this->tenantId();
        $storeKey = $this->storeId() ?? 'all';
        $key = "summary:$tenant:$storeKey:$from:$to";
        return $this->tagCache($key, function () use ($tenant, $from, $to) {
            $query = $this->db->table('orders')
                ->selectRaw("COUNT(*) FILTER (WHERE status = 'paid') as orders_count")
                ->selectRaw("CAST(SUM(CASE WHEN status = 'paid' THEN subtotal ELSE 0 END) as decimal(12,2)) as gross")
                ->selectRaw("CAST(SUM(CASE WHEN status = 'paid' THEN discount ELSE 0 END) as decimal(12,2)) as discount")
                ->selectRaw("CAST(SUM(CASE WHEN status = 'paid' THEN tax ELSE 0 END) as decimal(12,2)) as tax")
                ->selectRaw('CAST(SUM(COALESCE(refunded_total,0)) as decimal(12,2)) as refunds')
                ->selectRaw("CAST(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) - SUM(COALESCE(refunded_total,0)) as decimal(12,2)) as net")
                ->where('tenant_id', $tenant)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

            $this->applyStoreFilter($query, 'store_id');

            $row = $query->first();

            return [
                'orders_count' => (int) ($row->orders_count ?? 0),
                'gross' => number_format((float) ($row->gross ?? 0), 2, '.', ''),
                'discount' => number_format((float) ($row->discount ?? 0), 2, '.', ''),
                'tax' => number_format((float) ($row->tax ?? 0), 2, '.', ''),
                'refunds' => number_format((float) ($row->refunds ?? 0), 2, '.', ''),
                'net' => number_format((float) ($row->net ?? 0), 2, '.', ''),
            ];
        });
    }

    public function salesByDay(string $from, string $to): array
    {
        $tenant = $this->tenantId();
        $storeKey = $this->storeId() ?? 'all';
        $key = "byday:$tenant:$storeKey:$from:$to";
        return $this->tagCache($key, function () use ($tenant, $from, $to) {
            $query = $this->db->table('orders')
                ->selectRaw($this->dateExpression('created_at') . ' as d')
                ->selectRaw("CAST(SUM(CASE WHEN status = 'paid' THEN subtotal ELSE 0 END) as decimal(12,2)) as gross")
                ->selectRaw("CAST(SUM(CASE WHEN status = 'paid' THEN discount ELSE 0 END) as decimal(12,2)) as discount")
                ->selectRaw("CAST(SUM(CASE WHEN status = 'paid' THEN tax ELSE 0 END) as decimal(12,2)) as tax")
                ->selectRaw('CAST(SUM(COALESCE(refunded_total,0)) as decimal(12,2)) as refunds')
                ->selectRaw("CAST(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) - SUM(COALESCE(refunded_total,0)) as decimal(12,2)) as net")
                ->where('tenant_id', $tenant)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->groupBy('d')
                ->orderBy('d');

            $this->applyStoreFilter($query, 'store_id');

            $rows = $query->get();

            return $rows->map(fn($r) => [
                'date' => (string) $r->d,
                'gross' => number_format((float) $r->gross, 2, '.', ''),
                'discount' => number_format((float) $r->discount, 2, '.', ''),
                'tax' => number_format((float) $r->tax, 2, '.', ''),
                'refunds' => number_format((float) $r->refunds, 2, '.', ''),
                'net' => number_format((float) $r->net, 2, '.', ''),
            ])->all();
        });
    }

    public function topProducts(string $from, string $to, int $limit = 10): array
    {
        $tenant = $this->tenantId();
        $storeKey = $this->storeId() ?? 'all';
        $key = "topprod:$tenant:$storeKey:$from:$to:$limit";
        return $this->tagCache($key, function () use ($tenant, $from, $to, $limit) {
            $query = $this->db->table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->join('variants as v', 'v.id', '=', 'oi.variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('o.tenant_id', $tenant)
                ->whereBetween('o.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->whereIn('o.status', ['paid', 'refunded'])
                ->groupBy('p.id', 'p.title')
                ->selectRaw('p.id as product_id, p.title, CAST(SUM(oi.qty) as decimal(12,2)) as qty, CAST(SUM(oi.qty * oi.unit_price) as decimal(12,2)) as gross')
                ->orderByDesc($this->db->raw('SUM(oi.qty * oi.unit_price)'))
                ->limit($limit);

            $this->applyStoreFilter($query, 'o.store_id');

            $rows = $query->get();

            return $rows->map(fn($r) => [
                'product_id' => (string) $r->product_id,
                'title' => (string) $r->title,
                'qty' => number_format((float) $r->qty, 2, '.', ''),
                'gross' => number_format((float) $r->gross, 2, '.', ''),
            ])->all();
        });
    }

    public function topCustomers(string $from, string $to, int $limit = 10): array
    {
        $tenant = $this->tenantId();
        $storeKey = $this->storeId() ?? 'all';
        $key = "topcust:$tenant:$storeKey:$from:$to:$limit";
        return $this->tagCache($key, function () use ($tenant, $from, $to, $limit) {
            $query = $this->db->table('customer_orders as co')
                ->join('orders as o', 'o.id', '=', 'co.order_id')
                ->join('customers as c', 'c.id', '=', 'co.customer_id')
                ->where('o.tenant_id', $tenant)
                ->whereBetween('o.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->whereIn('o.status', ['paid', 'refunded'])
                ->groupBy('c.id', 'c.name')
                ->selectRaw('c.id as customer_id, c.name, COUNT(DISTINCT o.id) as orders, CAST(SUM(o.total) as decimal(12,2)) as gross')
                ->orderByDesc('gross')
                ->limit($limit);

            $this->applyStoreFilter($query, 'o.store_id');

            $rows = $query->get();

            return $rows->map(fn($r) => [
                'customer_id' => (string) $r->customer_id,
                'name' => (string) $r->name,
                'orders' => (int) $r->orders,
                'gross' => number_format((float) $r->gross, 2, '.', ''),
            ])->all();
        });
    }

    public function paymentMix(string $from, string $to): array
    {
        $tenant = $this->tenantId();
        $storeKey = $this->storeId() ?? 'all';
        $key = "paymix:$tenant:$storeKey:$from:$to";
        return $this->tagCache($key, function () use ($tenant, $from, $to) {
            $query = $this->db->table('payments as p')
                ->join('orders as o', 'o.id', '=', 'p.order_id')
                ->where('o.tenant_id', $tenant)
                ->whereBetween('o.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->where('p.status', 'captured')
                ->groupBy('p.method')
                ->selectRaw('p.method as method, CAST(SUM(p.amount) as decimal(12,2)) as total');

            $this->applyStoreFilter($query, 'o.store_id');

            $rows = $query->get();

            return $rows->map(fn($r) => [
                'method' => (string) $r->method,
                'total' => number_format((float) $r->total, 2, '.', ''),
            ])->all();
        });
    }

    public function taxBreakdown(string $from, string $to): array
    {
        // If tax tables or relationships are not present, return empty
        if (!$this->db->getSchemaBuilder()->hasTable('taxes')) {
            return [];
        }

        $tenant = $this->tenantId();
        $storeKey = $this->storeId() ?? 'all';
        $key = "taxbd:$tenant:$storeKey:$from:$to";
        return $this->tagCache($key, function () use ($tenant, $from, $to) {
            // best-effort tax aggregation relying on order-level tax total and names from product taxes
            // If you maintain per-line tax details, adapt here.
            $query = $this->db->table('orders')
                ->where('tenant_id', $tenant)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->whereIn('status', ['paid', 'refunded'])
                ->selectRaw('CAST(SUM(tax) as decimal(12,2)) as tax_total');

            $this->applyStoreFilter($query, 'store_id');

            $rows = $query->get();

            $sum = (float) ($rows->first()->tax_total ?? 0);
            if ($sum <= 0) return [];
            return [[ 'tax_name' => 'Total Tax', 'tax_total' => number_format($sum, 2, '.', '') ]];
        });
    }
}

