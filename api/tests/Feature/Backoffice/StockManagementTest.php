<?php

namespace Tests\Feature\Backoffice;

use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticateAdmin(Tenant $tenant): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user, ['*'], 'sanctum');

        return $user;
    }

    protected function createStore(Tenant $tenant): Store
    {
        return Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'address' => '123 Market Street',
            'phone' => '555-0100',
            'is_active' => true,
        ]);
    }

    protected function createProductWithVariant(Tenant $tenant): array
    {
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'track_stock' => true,
        ]);

        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'track_stock' => true,
            'is_default' => true,
            'barcode' => $product->barcode,
        ]);

        return [$product, $variant];
    }

    public function test_admin_can_adjust_stock_levels(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'stock-tenant']);
        $user = $this->authenticateAdmin($tenant);
        $store = $this->createStore($tenant);
        [$product, $variant] = $this->createProductWithVariant($tenant);

        $this->assertDatabaseMissing('stock_levels', [
            'tenant_id' => $tenant->id,
            'variant_id' => $variant->id,
            'store_id' => $store->id,
        ]);

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/stocks/adjust', [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'store_id' => $store->id,
                'qty_delta' => 5,
                'reason' => 'initial_stock',
                'note' => 'Initial stock load',
            ]);

        $response->assertOk();
        $this->assertEquals(5.0, (float) $response->json('data.qty_on_hand'));
        $response->assertJsonPath('data.tenant_id', $tenant->id);
        $response->assertJsonPath('data.store_id', $store->id);
        $response->assertJsonPath('data.variant_id', $variant->id);

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $tenant->id,
            'variant_id' => $variant->id,
            'store_id' => $store->id,
            'qty_on_hand' => 5.0,
        ]);

        $this->assertDatabaseHas('inventory_ledger', [
            'tenant_id' => $tenant->id,
            'variant_id' => $variant->id,
            'store_id' => $store->id,
            'qty_delta' => 5.0,
            'reason' => 'initial_stock',
            'user_id' => $user->id,
        ]);
    }

    public function test_cannot_reduce_stock_below_zero_when_disallowed(): void
    {
        config(['inventory.allow_negative_stock' => false]);

        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'no-negative']);
        $this->authenticateAdmin($tenant);
        $store = $this->createStore($tenant);
        [$product, $variant] = $this->createProductWithVariant($tenant);

        // Seed some stock first
        $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/stocks/adjust', [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'store_id' => $store->id,
                'qty_delta' => 2,
                'reason' => 'initial_stock',
            ])
            ->assertOk();

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/stocks/adjust', [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'store_id' => $store->id,
                'qty_delta' => -5,
                'reason' => 'correction',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['qty_delta']);
    }

    public function test_admin_can_list_stock_levels_for_product(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'list-stock']);
        $this->authenticateAdmin($tenant);
        $store = $this->createStore($tenant);
        [$product, $variant] = $this->createProductWithVariant($tenant);

        $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/stocks/adjust', [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'store_id' => $store->id,
                'qty_delta' => 3,
                'reason' => 'manual_adjustment',
            ])
            ->assertOk();

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/bo/stocks?product_id={$product->id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'product_id' => $product->id,
            'qty_on_hand' => 3.0,
        ]);
    }

    public function test_admin_can_adjust_using_variant_only(): void
    {
        $tenant = Tenant::create(['name' => 'Variant Only Tenant', 'slug' => 'variant-only']);
        $this->authenticateAdmin($tenant);
        $store = $this->createStore($tenant);
        [$product, $variant] = $this->createProductWithVariant($tenant);

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/stocks/adjust', [
                'variant_id' => $variant->id,
                'store_id' => $store->id,
                'qty_delta' => 4,
                'reason' => 'initial_stock',
            ]);

        $response->assertOk();
        $this->assertSame(4.0, (float) $response->json('data.qty_on_hand'));
        $response->assertJsonPath('data.product_id', $product->id);
    }

    public function test_requires_variant_when_product_has_multiple_variants(): void
    {
        $tenant = Tenant::create(['name' => 'Multi Variant Tenant', 'slug' => 'multi-variant']);
        $this->authenticateAdmin($tenant);
        $store = $this->createStore($tenant);
        [$product, $variant] = $this->createProductWithVariant($tenant);

        Variant::factory()->create([
            'product_id' => $product->id,
            'track_stock' => true,
        ]);

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/stocks/adjust', [
                'product_id' => $product->id,
                'store_id' => $store->id,
                'qty_delta' => 3,
                'reason' => 'initial_stock',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variant_id']);
        $this->assertSame(
            'Product has multiple variants; choose one.',
            $response->json('errors.variant_id.0')
        );
    }

    public function test_creates_default_variant_for_simple_product(): void
    {
        $tenant = Tenant::create(['name' => 'Simple Product Tenant', 'slug' => 'simple-product']);
        $this->authenticateAdmin($tenant);
        $store = $this->createStore($tenant);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'track_stock' => true,
            'barcode' => '1234567890123',
        ]);

        $this->assertDatabaseMissing('variants', ['product_id' => $product->id]);

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/stocks/adjust', [
                'product_id' => $product->id,
                'store_id' => $store->id,
                'qty_delta' => 7,
                'reason' => 'initial_stock',
            ]);

        $response->assertOk();

        $variantId = $response->json('data.variant_id');
        $this->assertNotEmpty($variantId);

        $variant = Variant::query()->find($variantId);
        $this->assertNotNull($variant);
        $this->assertTrue((bool) $variant->is_default);
        $this->assertSame($product->barcode, $variant->barcode);

        $this->assertDatabaseHas('stock_levels', [
            'tenant_id' => $tenant->id,
            'variant_id' => $variantId,
            'store_id' => $store->id,
            'qty_on_hand' => 7.0,
        ]);
    }
}
