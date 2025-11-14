<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Register;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AtlasBootstrap extends Command
{
    protected $signature = 'atlas:bootstrap {--force : Run even when SINGLE_TENANT is disabled}';

    protected $description = 'Bootstrap a single-tenant Atlas POS instance with demo data';

    public function handle(): int
    {
        if (!config('tenancy.single_tenant') && !$this->option('force')) {
            $this->warn('SINGLE_TENANT is disabled. Use --force to run the bootstrap seeder anyway.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            $slug = (string) config('tenancy.default_tenant_slug', 'default');
            $tenantName = Str::of($slug)->replace(['-', '_'], ' ')->title()->toString();

            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $tenantName]
            );

            $admin = User::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'email' => "admin@{$slug}.local",
                ],
                [
                    'name' => 'Atlas Admin',
                    'password' => Hash::make('password'),
                    'role' => 'admin',
                ]
            );

            $store = Store::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'code' => 'MAIN',
                ],
                [
                    'name' => 'Main Store',
                    'address' => '123 Main Street',
                    'phone' => '555-0100',
                    'is_active' => true,
                ]
            );

            if ($admin->store_id !== $store->id) {
                $admin->forceFill(['store_id' => $store->id])->save();
            }

            Register::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Front Register',
                ],
                [
                    'store_id' => $store->id,
                    'location' => 'Front Counter',
                    'is_active' => true,
                ]
            );

            $categoryNames = collect([
                'Beverages',
                'Snacks',
                'Household',
                'Desserts',
                'Prepared Meals',
            ]);

            $categories = $categoryNames->map(function (string $name) use ($tenant) {
                return Category::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $name,
                    ],
                    [
                        'slug' => Str::slug($name),
                        'is_active' => true,
                    ]
                );
            });

            if (!Product::query()->where('tenant_id', $tenant->id)->exists()) {
                Product::factory()
                    ->count(6)
                    ->state(fn () => ['tenant_id' => $tenant->id])
                    ->create()
                    ->each(function (Product $product) use ($categories, $store, $tenant) {
                        $category = $categories->isEmpty() ? null : $categories->random();
                        if ($category) {
                            $product->forceFill(['category_id' => $category->id])->save();
                        }

                        $variant = $product->ensureDefaultVariant();
                        if (!$variant->track_stock) {
                            $variant->forceFill(['track_stock' => true])->save();
                        }

                        StockLevel::updateOrCreate(
                            [
                                'tenant_id' => $tenant->id,
                                'variant_id' => $variant->id,
                                'store_id' => $store->id,
                            ],
                            [
                                'qty_on_hand' => 50,
                            ]
                        );
                    });
            }
        });

        $this->info('Atlas POS bootstrap complete.');

        return self::SUCCESS;
    }
}
