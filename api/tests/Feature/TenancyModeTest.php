<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TenancyModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_tenant_requests_bind_default_tenant(): void
    {
        Config::set('tenancy.single_tenant', true);
        Config::set('tenancy.default_tenant_slug', 'acme');

        $manager = app(TenantManager::class);
        $manager->forget();
        $tenant = $manager->ensure();

        $store = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'store_id' => $store->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', [
                'title' => 'Sample Product',
            ])
            ->assertCreated();

        $product = Product::query()->first();
        $this->assertNotNull($product);
        $this->assertSame($tenant->id, $product->tenant_id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_multi_tenant_requires_tenant_header(): void
    {
        Config::set('tenancy.single_tenant', false);
        Config::set('tenancy.default_tenant_slug', 'acme');

        app(TenantManager::class)->forget();

        $tenant = Tenant::factory()->create();
        $store = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'store_id' => $store->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products')
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->withHeaders([
                'X-Tenant' => $tenant->slug,
                'X-Store' => $store->id,
            ])
            ->getJson('/api/products')
            ->assertOk();
    }
}
