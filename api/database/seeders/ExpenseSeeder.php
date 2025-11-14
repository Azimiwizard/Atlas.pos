<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\Expense;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->first();

        if (!$tenant) {
            return;
        }

        $creator = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', ['admin', 'manager'])
            ->first();

        if (!$creator) {
            return;
        }

        $stores = Store::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        $categories = ['Facilities', 'Supplies', 'Payroll', 'Marketing', 'Utilities'];

        foreach ($stores as $store) {
            Expense::factory()
                ->count(3)
                ->state(fn () => ['category' => fake()->randomElement($categories)])
                ->create([
                    'tenant_id' => $tenant->id,
                    'store_id' => $store->id,
                    'created_by' => $creator->id,
                ]);
        }

        Expense::factory()
            ->count(3)
            ->state(fn () => ['category' => fake()->randomElement($categories)])
            ->create([
                'tenant_id' => $tenant->id,
                'store_id' => null,
                'created_by' => $creator->id,
            ]);
    }
}
