<?php

namespace Tests\Feature\Finance;

use App\Jobs\BackfillCogsForHistoricalOrders;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CogsBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_job(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'dispatch-tenant']);

        Bus::fake();

        $this->artisan('finance:cogs-backfill', [
            '--tenant' => $tenant->id,
        ])->assertExitCode(0);

        Bus::assertDispatched(BackfillCogsForHistoricalOrders::class, function ($job) use ($tenant) {
            return $job->tenantId === $tenant->id
                && $job->storeId === null
                && $job->fromDate === null;
        });
    }

    public function test_sync_command_backfills_missing_cogs_for_target_store(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'sync-tenant']);
        $storeA = Store::factory()->create(['tenant_id' => $tenant->id]);
        $storeB = Store::factory()->create(['tenant_id' => $tenant->id]);

        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        $variantA = Variant::factory()->create([
            'product_id' => $product->id,
            'cost' => 2.5,
            'price' => 9.99,
        ]);
        $variantB = Variant::factory()->create([
            'product_id' => $product->id,
            'cost' => 5.0,
            'price' => 12.99,
        ]);

        $orderDate = CarbonImmutable::parse('2025-01-10 12:00:00');

        $orderA = Order::create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeA->id,
            'status' => 'paid',
            'subtotal' => 0,
            'tax' => 0,
            'discount' => 0,
            'manual_discount' => 0,
            'total' => 0,
            'payment_method' => 'cash',
        ]);

        $orderB = Order::create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeB->id,
            'status' => 'paid',
            'subtotal' => 0,
            'tax' => 0,
            'discount' => 0,
            'manual_discount' => 0,
            'total' => 0,
            'payment_method' => 'cash',
        ]);

        $orderA->forceFill([
            'created_at' => $orderDate,
            'updated_at' => $orderDate,
        ])->saveQuietly();

        $orderB->forceFill([
            'created_at' => $orderDate,
            'updated_at' => $orderDate,
        ])->saveQuietly();

        $itemA = $orderA->items()->create([
            'tenant_id' => $tenant->id,
            'variant_id' => $variantA->id,
            'product_id' => $product->id,
            'qty' => 4,
            'unit_price' => 11,
        ]);

        $itemB = $orderB->items()->create([
            'tenant_id' => $tenant->id,
            'variant_id' => $variantB->id,
            'product_id' => $product->id,
            'qty' => 2,
            'unit_price' => 13,
        ]);

        $itemA->forceFill(['cogs_amount' => 0])->saveQuietly();
        $itemB->forceFill(['cogs_amount' => 0])->saveQuietly();

        $eligibleCount = OrderItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('cogs_amount', '<=', 0)
            ->whereHas('order', function ($query) use ($storeA, $orderDate) {
                $query->where('status', 'paid')
                    ->where('store_id', $storeA->id)
                    ->whereDate('created_at', '>=', $orderDate->toDateString())
                    ->whereDate('created_at', '<=', $orderDate->toDateString());
            })
            ->count();

        $this->assertSame(1, $eligibleCount);

        $this->artisan('finance:cogs-backfill', [
            '--tenant' => $tenant->id,
            '--store' => $storeA->id,
            '--from' => '2025-01-01',
            '--to' => '2025-01-31',
            '--sync' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('order_items', [
            'id' => $itemA->id,
            'cogs_amount' => number_format(4 * 2.5, 2, '.', ''),
        ]);

        $this->assertDatabaseHas('order_items', [
            'id' => $itemB->id,
            'cogs_amount' => '0.00',
        ]);
    }
}
