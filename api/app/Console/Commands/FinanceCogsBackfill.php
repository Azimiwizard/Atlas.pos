<?php

namespace App\Console\Commands;

use App\Jobs\BackfillCogsForHistoricalOrders;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class FinanceCogsBackfill extends Command
{
    protected $signature = 'finance:cogs-backfill
        {--tenant= : Target tenant id or slug}
        {--store= : Limit to a specific store id}
        {--from= : Inclusive start date (Y-m-d)}
        {--to= : Inclusive end date (Y-m-d)}
        {--sync : Run immediately instead of queuing the job}';

    protected $description = 'Backfill order-item COGS snapshots for finance analytics.';

    public function handle(): int
    {
        $tenant = $this->resolveTenant($this->option('tenant'));

        $store = $this->resolveStore($tenant, $this->option('store'));

        $from = $this->parseDateOption('from');
        $to = $this->parseDateOption('to');

        if ($from && $to && $from->greaterThan($to)) {
            $this->error('--from must be before or equal to --to.');

            return self::FAILURE;
        }

        $job = new BackfillCogsForHistoricalOrders(
            tenantId: $tenant->id,
            storeId: $store?->id,
            fromDate: $from?->toDateString(),
            toDate: $to?->toDateString()
        );

        if ($this->option('sync')) {
            $job->handle();
            $this->info('COGS backfill completed synchronously.');
        } else {
            dispatch($job);
            $this->info('COGS backfill job dispatched to the default queue.');
        }

        return self::SUCCESS;
    }

    protected function resolveTenant(?string $identifier): Tenant
    {
        if ($identifier) {
            $tenant = Tenant::query()
                ->where('id', $identifier)
                ->orWhere('slug', $identifier)
                ->first();

            if (!$tenant) {
                $this->error("Tenant [{$identifier}] was not found.");
                exit(self::FAILURE);
            }

            app(TenantManager::class)->setTenant($tenant);

            return $tenant;
        }

        $tenantManager = app(TenantManager::class);
        $tenantId = $tenantManager->id();

        if ($tenantId) {
            return Tenant::query()->findOrFail($tenantId);
        }

        $slug = (string) config('tenancy.default_tenant_slug', 'default');
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => Str::of($slug)->replace(['-', '_'], ' ')->title()->toString()]
        );

        $tenantManager->setTenant($tenant);

        return $tenant;
    }

    protected function resolveStore(Tenant $tenant, ?string $storeId): ?Store
    {
        if (!$storeId) {
            return null;
        }

        $store = Store::query()
            ->where('tenant_id', $tenant->id)
            ->firstWhere('id', $storeId);

        if (!$store) {
            $this->error('Store not found for this tenant.');
            exit(self::FAILURE);
        }

        return $store;
    }

    protected function parseDateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if (!$value) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable $exception) {
            $this->error("Invalid date for --{$name}. Use YYYY-MM-DD.");
            exit(self::FAILURE);
        }
    }
}
