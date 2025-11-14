<?php

namespace Tests\Feature\Backoffice;

use App\Domain\Finance\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.single_tenant' => false]);
    }

    /**
     * @return array{0: Tenant, 1: Store, 2: User, 3: Variant}
     */
    protected function createFinanceContext(array $variantOverrides = []): array
    {
        $tenant = Tenant::factory()->create();
        $store = Store::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'store_id' => $store->id,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $variant = Variant::factory()->create(array_merge([
            'product_id' => $product->id,
            'price' => 100,
            'cost' => 40,
            'track_stock' => false,
        ], $variantOverrides));

        return [$tenant, $store, $user, $variant];
    }

    public function test_finance_summary_returns_expected_metrics(): void
    {
        [$tenant, $store, $user] = $this->seedFinanceLedger();

        Sanctum::actingAs($user, ['*'], 'sanctum');

        $query = http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-03-31',
            'currency' => 'USD',
            'store_id' => $store->id,
            'tz' => 'Africa/Casablanca',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/summary?'.$query);

        $response->assertOk()
            ->assertJson([
                'revenue' => 450.0,
                'cogs' => 180.0,
                'gross_profit' => 270.0,
                'gross_margin' => 60.0,
                'expenses_total' => 80.0,
                'net_profit' => 190.0,
                'net_margin' => round((190 / 450) * 100, 2),
                'avg_ticket' => 150.0,
                'orders_count' => 3,
            ]);
    }

    public function test_finance_flow_returns_monthly_buckets_with_net_and_profit(): void
    {
        [$tenant, $store, $user] = $this->seedFinanceLedger();

        Sanctum::actingAs($user, ['*'], 'sanctum');

        $query = http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-03-31',
            'currency' => 'USD',
            'store_id' => $store->id,
            'tz' => 'Africa/Casablanca',
            'bucket' => 'month',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/flow?'.$query);

        $response->assertOk()->assertJson([
            ['period' => '2025-01', 'cash_in' => 150.0, 'cash_out' => 60.0, 'net' => 90.0, 'profit' => 90.0],
            ['period' => '2025-02', 'cash_in' => 80.0, 'cash_out' => 40.0, 'net' => 40.0, 'profit' => 40.0],
            ['period' => '2025-03', 'cash_in' => 220.0, 'cash_out' => 80.0, 'net' => 140.0, 'profit' => 140.0],
        ]);
    }

    public function test_finance_expenses_endpoint_returns_category_breakdown(): void
    {
        [$tenant, $store, $user] = $this->seedFinanceLedger();
        Sanctum::actingAs($user, ['*'], 'sanctum');

        $query = http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-03-31',
            'currency' => 'USD',
            'store_id' => $store->id,
            'tz' => 'Africa/Casablanca',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/expenses?'.$query);

        $response->assertOk()
            ->assertJsonStructure([
                '*' => ['category', 'amount', 'percent'],
            ]);
    }

    public function test_finance_health_returns_score_and_signals(): void
    {
        [$tenant, $store, $user] = $this->seedFinanceLedger();
        Sanctum::actingAs($user, ['*'], 'sanctum');

        $query = http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-03-31',
            'currency' => 'USD',
            'store_id' => $store->id,
            'tz' => 'Africa/Casablanca',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/health?'.$query);

        $response->assertOk()
            ->assertJsonStructure([
                'score',
                'signals' => [
                    '*' => ['label', 'level', 'detail', 'period'],
                ],
            ])
            ->assertJson(fn (AssertableJson $json) => $json
                ->whereType('score', 'integer')
                ->has('signals', fn (AssertableJson $signals) => $signals
                    ->each(fn (AssertableJson $signal) => $signal
                        ->whereType('label', 'string')
                        ->whereType('level', 'string')
                        ->whereType('detail', 'string')
                        ->whereType('period', 'string')
                    )
                )
            );

        $this->assertNotEmpty($response->json('signals'));
    }

    public function test_finance_health_flags_revenue_drop_week_over_week(): void
    {
        [$tenant, $store, $user, $variant] = $this->createFinanceContext();
        Sanctum::actingAs($user, ['*'], 'sanctum');

        // Previous week revenue (April 1-7).
        $this->createPaidOrder($tenant, $store, $variant, 5, 100, '2025-04-02 10:00:00');
        $this->createPaidOrder($tenant, $store, $variant, 5, 95, '2025-04-05 16:00:00');

        // Current week revenue (April 8-14) significantly lower.
        $this->createPaidOrder($tenant, $store, $variant, 2, 85, '2025-04-09 12:00:00');
        $this->createPaidOrder($tenant, $store, $variant, 1, 80, '2025-04-12 18:00:00');

        $query = http_build_query([
            'date_from' => '2025-03-15',
            'date_to' => '2025-04-14',
            'currency' => 'USD',
            'store_id' => $store->id,
            'tz' => 'UTC',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/health?'.$query);

        $response->assertOk();

        $signal = collect($response->json('signals'))
            ->firstWhere('label', 'Revenue ↓ ≥10% WoW');

        $this->assertNotNull($signal);
        $this->assertSame('alert', $signal['level']);
        $this->assertSame('week', $signal['period']);
    }

    public function test_finance_health_flags_gross_margin_drop_month_over_month(): void
    {
        [$tenant, $store, $user, $highMarginVariant] = $this->createFinanceContext([
            'price' => 100,
            'cost' => 20,
        ]);

        $lowMarginVariant = Variant::factory()->create([
            'product_id' => $highMarginVariant->product_id,
            'price' => 100,
            'cost' => 75,
            'track_stock' => false,
        ]);

        Sanctum::actingAs($user, ['*'], 'sanctum');

        foreach ([5, 12, 20] as $day) {
            $this->createPaidOrder(
                $tenant,
                $store,
                $highMarginVariant,
                4,
                100,
                sprintf('2025-05-%02d 12:00:00', $day)
            );
        }

        foreach ([4, 15, 26] as $day) {
            $this->createPaidOrder(
                $tenant,
                $store,
                $lowMarginVariant,
                4,
                100,
                sprintf('2025-06-%02d 12:00:00', $day)
            );
        }

        $query = http_build_query([
            'date_from' => '2025-04-15',
            'date_to' => '2025-06-30',
            'currency' => 'USD',
            'store_id' => $store->id,
            'tz' => 'UTC',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/health?'.$query);

        $response->assertOk();

        $signal = collect($response->json('signals'))
            ->firstWhere('label', 'Gross margin ↓ ≥3pp MoM');

        $this->assertNotNull($signal);
        $this->assertSame('alert', $signal['level']);
        $this->assertSame('month', $signal['period']);
    }

    public function test_finance_health_flags_expense_spike_by_category(): void
    {
        [$tenant, $store, $user, $variant] = $this->createFinanceContext();
        Sanctum::actingAs($user, ['*'], 'sanctum');

        foreach (['2025-01-10', '2025-02-10', '2025-03-10'] as $date) {
            Expense::create([
                'tenant_id' => $tenant->id,
                'store_id' => $store->id,
                'category' => 'Marketing',
                'amount' => 100,
                'incurred_at' => $date.' 12:00:00',
                'vendor' => 'Ad Co',
            ]);
        }

        Expense::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'category' => 'Marketing',
            'amount' => 260,
            'incurred_at' => '2025-04-15 09:00:00',
            'vendor' => 'Ad Co',
        ]);

        Expense::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'category' => 'Rent',
            'amount' => 200,
            'incurred_at' => '2025-04-10 10:00:00',
            'vendor' => 'Downtown Plaza',
        ]);

        $this->createPaidOrder($tenant, $store, $variant, 3, 120, '2025-04-10 10:00:00');

        $query = http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-04-30',
            'currency' => 'USD',
            'store_id' => $store->id,
            'tz' => 'UTC',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/health?'.$query);

        $response->assertOk();

        $signal = collect($response->json('signals'))
            ->firstWhere('label', 'Expense spike: Marketing');

        $this->assertNotNull($signal);
        $this->assertSame('alert', $signal['level']);
        $this->assertSame('month', $signal['period']);
    }

    public function test_finance_health_flags_consecutive_negative_net_months(): void
    {
        [$tenant, $store, $user, $variant] = $this->createFinanceContext([
            'price' => 110,
            'cost' => 70,
        ]);

        Sanctum::actingAs($user, ['*'], 'sanctum');

        foreach (['2025-05-05', '2025-05-21'] as $date) {
            $this->createPaidOrder($tenant, $store, $variant, 5, 110, $date.' 12:00:00');
        }

        foreach (['2025-06-06', '2025-06-24'] as $date) {
            $this->createPaidOrder($tenant, $store, $variant, 5, 110, $date.' 12:00:00');
        }

        Expense::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'category' => 'Overhead',
            'amount' => 1500,
            'incurred_at' => '2025-05-28 08:00:00',
            'vendor' => 'Vendors Inc',
        ]);

        Expense::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'category' => 'Overhead',
            'amount' => 1400,
            'incurred_at' => '2025-06-28 08:00:00',
            'vendor' => 'Vendors Inc',
        ]);

        $query = http_build_query([
            'date_from' => '2025-05-01',
            'date_to' => '2025-06-30',
            'currency' => 'USD',
            'store_id' => $store->id,
            'tz' => 'UTC',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/health?'.$query);

        $response->assertOk();

        $signal = collect($response->json('signals'))
            ->firstWhere('label', 'Negative net for 2 consecutive months');

        $this->assertNotNull($signal);
        $this->assertSame('alert', $signal['level']);
        $this->assertSame('month', $signal['period']);
    }

    public function test_manager_cannot_access_other_store_finance(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'store-scope']);
        $storeA = Store::factory()->create(['tenant_id' => $tenant->id]);
        $storeB = Store::factory()->create(['tenant_id' => $tenant->id]);

        $manager = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'manager',
            'store_id' => $storeA->id,
        ]);

        Sanctum::actingAs($manager, ['*'], 'sanctum');

        $query = http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-10',
            'currency' => 'USD',
            'store_id' => $storeB->id,
            'tz' => 'Africa/Casablanca',
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $storeA->id,
        ])->getJson('/api/bo/finance/summary?'.$query);

        $response->assertForbidden();
    }
    /**
     * @return array{0: Tenant, 1: Store, 2: User}
     */
    protected function seedFinanceLedger(): array
    {
        $tenant = Tenant::factory()->create(['slug' => 'finance-tenant']);
        $store = Store::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'store_id' => $store->id,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'price' => 50,
        ]);

        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'price' => 50,
            'cost' => 20,
            'track_stock' => false,
        ]);

        $orders = [
            ['ts' => '2025-01-15 10:00:00', 'qty' => 3, 'price' => 50.0],
            ['ts' => '2025-02-05 22:30:00', 'qty' => 2, 'price' => 40.0],
            ['ts' => '2025-03-30 00:30:00', 'qty' => 4, 'price' => 55.0],
        ];

        foreach ($orders as $entry) {
            $this->createPaidOrder($tenant, $store, $variant, $entry['qty'], $entry['price'], $entry['ts']);
        }

        Expense::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'category' => 'Rent',
            'amount' => 50,
            'incurred_at' => '2025-02-10 12:00:00',
            'vendor' => 'Downtown Plaza',
        ]);

        Expense::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'category' => 'Marketing',
            'amount' => 30,
            'incurred_at' => '2025-03-05 08:00:00',
            'vendor' => 'Ad Agency',
        ]);

        return [$tenant, $store, $user];
    }

    protected function createPaidOrder(
        Tenant $tenant,
        Store $store,
        Variant $variant,
        float $qty,
        float $price,
        string $timestamp
    ): void {
        $total = round($qty * $price, 2);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'status' => 'paid',
            'subtotal' => $total,
            'tax' => 0,
            'discount' => 0,
            'manual_discount' => 0,
            'total' => $total,
            'payment_method' => 'cash',
        ]);

        $order->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save();

        $order->items()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $variant->product_id,
            'variant_id' => $variant->id,
            'qty' => $qty,
            'unit_price' => $price,
        ]);
    }
}

