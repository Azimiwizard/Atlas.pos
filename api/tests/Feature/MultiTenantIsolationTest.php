<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_access_order_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $storeA = Store::factory()->for($tenantA)->create();
        $storeB = Store::factory()->for($tenantB)->create();

        $admin = User::factory()
            ->for($tenantA)
            ->create([
                'role' => 'admin',
            ]);

        $orderForTenantB = Order::create([
            'tenant_id' => $tenantB->id,
            'store_id' => $storeB->id,
            'status' => 'draft',
            'subtotal' => 0,
            'tax' => 0,
            'discount' => 0,
            'manual_discount' => 0,
            'total' => 0,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeaders([
                'X-Tenant' => $tenantA->slug,
                'X-Store' => $storeA->id,
            ])
            ->getJson("/api/orders/{$orderForTenantB->id}");

        $response->assertStatus(404);
    }

    public function test_cashier_cannot_switch_store_via_header(): void
    {
        $tenant = Tenant::factory()->create();
        $storePrimary = Store::factory()->for($tenant)->create();
        $storeOther = Store::factory()->for($tenant)->create();

        $cashier = User::factory()
            ->for($tenant)
            ->create([
                'role' => 'cashier',
                'store_id' => $storePrimary->id,
            ]);

        $response = $this->actingAs($cashier, 'sanctum')
            ->withHeaders([
                'X-Tenant' => $tenant->slug,
                'X-Store' => $storeOther->id,
            ])
            ->getJson('/api/orders');

        $response->assertStatus(403);
    }

    public function test_store_context_cleared_after_request(): void
    {
        $tenant = Tenant::factory()->create();
        $store = Store::factory()->for($tenant)->create();

        $admin = User::factory()
            ->for($tenant)
            ->create([
                'role' => 'admin',
            ]);

        $service = app(StoreManager::class);
        $this->assertNull($service->id());

        $this->actingAs($admin, 'sanctum')
            ->withHeaders([
                'X-Tenant' => $tenant->slug,
                'X-Store' => $store->id,
            ])
            ->getJson('/api/orders')
            ->assertStatus(200);

        $this->assertNull(app(StoreManager::class)->id(), 'Store context did not reset after request.');
    }
}
