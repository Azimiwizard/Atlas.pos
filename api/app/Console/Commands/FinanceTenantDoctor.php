<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class FinanceTenantDoctor extends Command
{
    protected $signature = 'finance:tenant:doctor {--refresh : Seed the default tenant/store/user even if they exist}';

    protected $description = 'Diagnose tenant/store setup and ensure default local tenant is available';

    public function handle(): int
    {
        $this->line('Inspecting tenants (up to 10 rows)...');
        $this->table(
            ['id', 'name', 'slug', 'is_active', 'deleted_at'],
            Tenant::select('id', 'name', 'slug', 'is_active', 'deleted_at')
                ->orderBy('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($tenant) => $tenant->toArray())
                ->toArray()
        );

        if (Schema::hasTable('tenant_domains')) {
            $this->line('tenant_domains (up to 10 rows)...');
            $rows = \DB::table('tenant_domains')
                ->select('id', 'tenant_id', 'domain')
                ->limit(10)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->toArray();
            if (empty($rows)) {
                $this->warn('No tenant_domains records found.');
            } else {
                $this->table(['id', 'tenant_id', 'domain'], $rows);
            }
        } else {
            $this->warn('tenant_domains table not present.');
        }

        $this->line('Stores (up to 10 rows)...');
        $this->table(
            ['id', 'tenant_id', 'name', 'is_active'],
            Store::select('id', 'tenant_id', 'name', 'is_active')
                ->orderBy('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($store) => $store->toArray())
                ->toArray()
        );

        $this->line('Users (up to 10 rows)...');
        $this->table(
            ['id', 'tenant_id', 'email', 'role'],
            User::select('id', 'tenant_id', 'email', 'role')
                ->orderBy('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($user) => $user->only(['id', 'tenant_id', 'email', 'role']))
                ->toArray()
        );

        if (app()->environment('local') || $this->option('refresh')) {
            $this->info('Ensuring default tenant/store/user via TenantDemoSeeder...');
            $this->call(TenantDemoSeeder::class);
        } else {
            $this->warn('Skipping automatic creation because environment is not local. Use --refresh to force.');
        }

        $default = Tenant::where('slug', 'default')->where('is_active', true)->first();

        if ($default) {
            $this->info('Default tenant details:');
            $this->line(sprintf('Slug: %s', $default->slug));
            $this->line(sprintf('ID: %s', $default->id));
            $store = $default->stores()->where('is_active', true)->orderBy('created_at')->first();
            if ($store) {
                $this->line(sprintf('Store: %s (%s)', $store->name, $store->id));
            } else {
                $this->warn('No active store linked to default tenant.');
            }

            $curlPayload = json_encode([
                'email' => 'manager@example.com',
                'password' => 'password',
                'tenant' => 'default',
            ], JSON_UNESCAPED_SLASHES);

            $this->info('Example login curl (slug):');
            $this->line(sprintf("curl -X POST http://127.0.0.1:8000/api/auth/login -H 'Content-Type: application/json' -d '%s'", $curlPayload));

            $curlPayloadId = json_encode([
                'email' => 'manager@example.com',
                'password' => 'password',
                'tenant' => $default->id,
            ], JSON_UNESCAPED_SLASHES);

            $this->info('Example login curl (id):');
            $this->line(sprintf("curl -X POST http://127.0.0.1:8000/api/auth/login -H 'Content-Type: application/json' -d '%s'", $curlPayloadId));
        } else {
            $this->error('Default tenant (slug=default) not found.');
        }

        return self::SUCCESS;
    }
}
