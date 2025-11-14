<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use Database\Seeders\TenantDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTenantFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_tenant_login_works_with_seeded_preset(): void
    {
        $this->seed(TenantDemoSeeder::class);

        $tenant = Tenant::where('slug', 'default')->firstOrFail();

        $response = $this->postJson('/api/auth/login', [
            'tenant' => 'default',
            'email' => 'manager@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('tenant.slug', 'default')
            ->assertJsonStructure([
                'token',
                'tenant' => ['id', 'slug', 'name'],
                'user' => ['id', 'email', 'role', 'tenant', 'store'],
            ]);
    }
}
