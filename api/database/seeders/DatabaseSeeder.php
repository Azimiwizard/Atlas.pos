<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Register;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Database\Seeders\DemoRefundSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo'],
            ['name' => 'Demo Tenant', 'is_active' => true]
        );

        $admin = User::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'email' => 'admin@example.com',
            ],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        $manager = User::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'email' => 'manager@example.com',
            ],
            [
                'name' => 'Demo Manager',
                'password' => Hash::make('demo1234'),
                'role' => 'manager',
            ]
        );

        $cashier = User::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'email' => 'cashier@example.com',
            ],
            [
                'name' => 'Demo Cashier',
                'password' => Hash::make('demo1234'),
                'role' => 'cashier',
            ]
        );

        $mainStore = Store::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'MAIN',
            ],
            [
                'name' => 'Main Store',
                'address' => '123 Market Street',
                'phone' => '555-1000',
                'is_active' => true,
            ]
        );

        $branchStore = Store::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'BRANCH',
            ],
            [
                'name' => 'Branch Store',
                'address' => '456 River Road',
                'phone' => '555-2000',
                'is_active' => true,
            ]
        );

        $offlineStore = Store::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'OFFLINE',
            ],
            [
                'name' => 'Offline Warehouse',
                'address' => '789 Depot Lane',
                'phone' => '555-3000',
                'is_active' => false,
            ]
        );

        $stores = collect([$mainStore, $branchStore, $offlineStore]);

        if ($cashier->store_id !== $mainStore->id) {
            $cashier->store_id = $mainStore->id;
            $cashier->save();
        }

        $registerConfigs = [
            ['name' => 'Main Counter', 'location' => 'Front Entrance', 'store' => $mainStore],
            ['name' => 'Branch Counter', 'location' => 'Lobby', 'store' => $branchStore],
        ];

        collect($registerConfigs)->each(function (array $data) use ($tenant) {
            Register::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'name' => $data['name'],
                ],
                [
                    'store_id' => $data['store']->id,
                    'location' => $data['location'],
                    'is_active' => true,
                ]
            );
        });

        $products = Product::factory()
            ->count(20)
            ->for($tenant)
            ->create()
            ->each(function (Product $product) use ($stores, $tenant) {
                $variantCount = fake()->numberBetween(1, 2);

                $variants = \App\Models\Variant::factory()
                    ->count($variantCount)
                    ->for($product)
                    ->create();

                if ($variants->isEmpty()) {
                    $defaultVariant = $product->ensureDefaultVariant();
                    $variants = collect([$defaultVariant]);
                } else {
                    $defaultVariant = $variants->first();
                    $shouldRefresh = false;

                    if (!$defaultVariant->is_default) {
                        $defaultVariant->is_default = true;
                        $shouldRefresh = true;
                    }

                    if ($product->barcode && !$defaultVariant->barcode) {
                        $defaultVariant->barcode = $product->barcode;
                        $shouldRefresh = true;
                    }

                    if ($shouldRefresh) {
                        $defaultVariant->save();
                        $variants = $product->variants()->get();
                    }
                }

                foreach ($variants as $variant) {
                    $stores->each(function (Store $store) use ($tenant, $variant) {
                        StockLevel::updateOrCreate(
                            [
                                'tenant_id' => $tenant->id,
                                'variant_id' => $variant->id,
                                'store_id' => $store->id,
                            ],
                            [
                                'qty_on_hand' => fake()->numberBetween(20, 100),
                            ]
                        );
                    });
                }
            });

        $categories = collect([
            'Beverages',
            'Snacks',
            'Household',
            'Other',
            'Burgers',
            'Drinks',
            'Sides',
            'Desserts',
        ])->unique()->map(function (string $name) use ($tenant) {
            return Category::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => Str::slug($name),
                ],
                [
                    'name' => $name,
                    'is_active' => true,
                ]
            );
        });

        $taxes = collect([
            ['name' => 'VAT', 'rate' => 10, 'inclusive' => false],
            ['name' => 'City Tax', 'rate' => 5, 'inclusive' => true],
        ])->map(function (array $tax) use ($tenant) {
            return Tax::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'name' => $tax['name'],
                ],
                [
                    'rate' => $tax['rate'],
                    'inclusive' => $tax['inclusive'],
                    'is_active' => true,
                ]
            );
        });

        $products->each(function (Product $product) use ($categories, $taxes) {
            $categoryCount = fake()->numberBetween(1, min(2, $categories->count()));
            $taxCount = fake()->numberBetween(1, min(2, $taxes->count()));

            $selectedCategories = collect($categories->random($categoryCount));
            $selectedTaxes = collect($taxes->random($taxCount));

            $product->categories()->sync($selectedCategories->pluck('id')->all());
            $product->taxes()->sync($selectedTaxes->pluck('id')->all());

            $primaryCategoryId = $selectedCategories->pluck('id')->first();

            if ($primaryCategoryId && $product->category_id !== $primaryCategoryId) {
                $product->forceFill(['category_id' => $primaryCategoryId])->save();
            }
        });

        $snacksCategory = $categories->firstWhere('name', 'Snacks');
        $featuredProduct = $products->random();

        Promotion::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'name' => 'Snacks 15% Off',
            ],
            [
                'type' => 'percent',
                'value' => 15,
                'applies_to' => 'category',
                'category_id' => $snacksCategory?->id,
                'product_id' => null,
                'is_active' => true,
            ]
        );

        Promotion::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'name' => 'Promo $2 Off Product',
            ],
            [
                'type' => 'amount',
                'value' => 2,
                'applies_to' => 'product',
                'product_id' => $featuredProduct->id,
                'category_id' => null,
                'is_active' => true,
            ]
        );

        $customers = Customer::factory()
            ->count(15)
            ->create([
                'tenant_id' => $tenant->id,
            ]);

        $customers->each(function (Customer $customer) use ($stores) {
            if (!$customer->store_id) {
                $customer->store_id = $stores->random()->id;
                $customer->save();
            }
        });

        $variants = Variant::query()
            ->whereHas('product', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->get();

        if ($variants->isNotEmpty()) {
            $customers->each(function (Customer $customer) use ($variants, $tenant, $admin, $cashier, $manager, $stores, $mainStore) {
                $ordersToCreate = fake()->numberBetween(1, 3);
                $store = $stores->firstWhere('id', $customer->store_id) ?? $stores->random();
                $assignedCashier = $store->id === $mainStore->id ? $cashier : $manager;

                for ($i = 0; $i < $ordersToCreate; $i++) {
                    $createdAt = now()->subDays(fake()->numberBetween(0, 45));

                    $order = Order::create([
                        'tenant_id' => $tenant->id,
                        'store_id' => $store->id,
                        'cashier_id' => $assignedCashier->id,
                        'status' => 'paid',
                        'subtotal' => 0,
                        'tax' => 0,
                        'discount' => 0,
                        'manual_discount' => 0,
                        'total' => 0,
                    ]);

                    $lineItems = fake()->randomElements($variants->all(), fake()->numberBetween(1, 3));
                    $subtotal = 0;

                    foreach ($lineItems as $variant) {
                        $qty = fake()->numberBetween(1, 3);
                        $price = (float) $variant->price;
                        $lineTotal = $qty * $price;
                        $subtotal += $lineTotal;

                        OrderItem::create([
                            'tenant_id' => $tenant->id,
                            'order_id' => $order->id,
                            'variant_id' => $variant->id,
                            'product_id' => $variant->product_id,
                            'qty' => $qty,
                            'unit_price' => $price,
                        ]);

                        $stock = StockLevel::firstOrCreate(
                            [
                                'tenant_id' => $tenant->id,
                                'variant_id' => $variant->id,
                                'store_id' => $store->id,
                            ],
                            [
                                'qty_on_hand' => 0,
                            ]
                        );

                        $stock->qty_on_hand = max($stock->qty_on_hand - $qty, 0);
                        $stock->save();
                    }

                    $order->forceFill([
                        'subtotal' => round($subtotal, 2),
                        'manual_discount' => 0,
                        'discount' => 0,
                        'tax' => 0,
                        'total' => round($subtotal, 2),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ])->save();

                    Payment::create([
                        'tenant_id' => $tenant->id,
                        'order_id' => $order->id,
                        'method' => fake()->randomElement(['cash', 'card']),
                        'amount' => $order->total,
                        'status' => 'captured',
                        'captured_at' => $createdAt->copy()->addMinutes(fake()->numberBetween(5, 60)),
                    ]);

                    CustomerOrder::create([
                        'customer_id' => $customer->id,
                        'order_id' => $order->id,
                    ]);

                    $customer->increment('loyalty_points', (int) floor($order->total));
                }
            });
        }

        // Demo refunds
        $this->call(DemoRefundSeeder::class);
        $this->call(ExpenseSeeder::class);
        $this->call(FinanceDemoSeeder::class);
        $this->call(TenantDemoSeeder::class);
    }
}
