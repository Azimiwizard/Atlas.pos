<?php

namespace Tests\Feature\Backoffice;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.default' => 'public']);
    }

    public function test_admin_can_create_and_list_products(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Burgers',
            'slug' => 'burgers',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*'], 'sanctum');

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/products', [
                'title' => 'Cheese Burger',
                'price' => 12.50,
                'sku' => 'SKU-12345',
                'barcode' => '1234567890123',
                'category_id' => $category->id,
                'track_stock' => true,
                'is_active' => true,
            ]);

        $response->assertCreated();
        $productId = $response->json('data.id');

        $this->assertNotNull($productId);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'tenant_id' => $tenant->id,
            'title' => 'Cheese Burger',
            'category_id' => $category->id,
        ]);

        $list = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/bo/products?q=Cheese');

        $list->assertOk();
        $list->assertJsonPath('data.0.id', $productId);
        $list->assertJsonPath('data.0.category_name', 'Burgers');
    }

    public function test_can_fetch_backoffice_categories(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'manager']);

        Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Desserts',
            'slug' => 'desserts',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['*'], 'sanctum');

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/bo/categories');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Desserts']);
    }

    public function test_upload_rejects_non_image_files(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        Sanctum::actingAs($user, ['*'], 'sanctum');
        Storage::fake('public');

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/uploads/images', [
                'file' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
            ]);

        $response->assertStatus(422);
    }
}


