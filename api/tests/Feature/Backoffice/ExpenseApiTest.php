<?php

namespace Tests\Feature\Backoffice;

use App\Domain\Finance\Models\Expense;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_crud_expenses(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'finance-test']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $store = Store::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin, ['*'], 'sanctum');

        $payload = [
            'store_id' => $store->id,
            'category' => 'Marketing',
            'amount' => 125.75,
            'incurred_at' => now()->subDay()->toDateTimeString(),
            'vendor' => 'Google Ads',
            'notes' => 'Launch campaign',
        ];

        $create = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/expenses', $payload);

        $create->assertCreated();
        $expenseId = $create->json('data.id');

        $this->assertDatabaseHas('expenses', [
            'id' => $expenseId,
            'tenant_id' => $tenant->id,
            'category' => 'Marketing',
            'store_id' => $store->id,
            'created_by' => $admin->id,
        ]);

        $show = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/bo/expenses/{$expenseId}");

        $show->assertOk()->assertJsonPath('data.vendor', 'Google Ads');

        $update = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->putJson("/api/bo/expenses/{$expenseId}", [
                'notes' => 'Updated note',
                'amount' => 150.25,
            ]);

        $update->assertOk()->assertJsonPath('data.notes', 'Updated note');

        $list = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson("/api/bo/expenses?store_id={$store->id}");

        $list->assertOk();
        $list->assertJsonPath('data.0.id', $expenseId);

        $delete = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/bo/expenses/{$expenseId}");

        $delete->assertNoContent();
        $this->assertDatabaseMissing('expenses', ['id' => $expenseId]);
    }

    public function test_manager_is_scoped_to_their_store(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'scope-test']);
        $storeA = Store::factory()->create(['tenant_id' => $tenant->id]);
        $storeB = Store::factory()->create(['tenant_id' => $tenant->id]);

        $manager = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'manager',
            'store_id' => $storeA->id,
        ]);

        Expense::factory()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeA->id,
            'category' => 'Supplies',
        ]);

        Expense::factory()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeB->id,
            'category' => 'Payroll',
        ]);

        Expense::factory()->create([
            'tenant_id' => $tenant->id,
            'store_id' => null,
            'category' => 'Marketing',
        ]);

        Sanctum::actingAs($manager, ['*'], 'sanctum');

        $index = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/bo/expenses');

        $index->assertOk();
        $storeIds = collect($index->json('data'))->pluck('store_id');
        $this->assertTrue($storeIds->contains($storeA->id));
        $this->assertFalse($storeIds->contains($storeB->id));

        $create = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/bo/expenses', [
                'store_id' => $storeB->id,
                'category' => 'Facilities',
                'amount' => 55.25,
                'incurred_at' => now()->toDateTimeString(),
            ]);

        $create->assertStatus(422);
        $create->assertJsonValidationErrors('store_id');
    }

    public function test_cashiers_cannot_access_expenses(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'cashier-test']);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'cashier']);

        Sanctum::actingAs($cashier, ['*'], 'sanctum');

        $response = $this
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/bo/expenses');

        $response->assertForbidden();
    }
}
