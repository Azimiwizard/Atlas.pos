<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderItemCogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_items_capture_cogs_snapshot_on_creation_and_update(): void
    {
        $tenant = Tenant::factory()->create();
        $store = Store::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'manager',
            'store_id' => $store->id,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'track_stock' => false,
        ]);

        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'price' => 12.50,
            'cost' => 4.25,
            'is_default' => true,
            'track_stock' => false,
        ]);

        $this->actingAs($user);

        $service = app(OrderService::class);

        $order = $service->createDraft($user, $store->id);
        $service->addItem($order->id, $variant->id, 2);

        $expectedInitial = number_format(2 * 4.25, 2, '.', '');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'cogs_amount' => $expectedInitial,
        ]);

        $service->addItem($order->id, $variant->id, 1);

        $expectedUpdated = number_format(3 * 4.25, 2, '.', '');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'cogs_amount' => $expectedUpdated,
        ]);
    }
}
