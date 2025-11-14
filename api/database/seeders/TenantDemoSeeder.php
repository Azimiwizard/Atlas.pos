<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Default Tenant',
                'is_active' => true,
            ]
        );

        $store = Store::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'DEFAULT',
            ],
            [
                'name' => 'Default Store',
                'address' => '123 Demo Street',
                'phone' => '555-0000',
                'is_active' => true,
            ]
        );

        $manager = User::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'email' => 'manager@example.com',
                ],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Demo Manager',
                    'password' => Hash::make('password'),
                    'role' => 'manager',
                    'store_id' => $store->id,
                ]
            );

        $this->command?->info('TenantDemoSeeder ensured default tenant, store and manager user exist.');
        $this->command?->line(sprintf('Tenant slug: %s | Tenant ID: %s', $tenant->slug, $tenant->id));
        $this->command?->line(sprintf('Store ID: %s', $store->id));
        $this->command?->line(sprintf('Manager login -> email: %s password: %s', $manager->email, 'password'));
    }
}
