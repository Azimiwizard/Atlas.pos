<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_summary_has_expected_keys(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user, 'sanctum');

        $store = \App\Models\Store::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'address' => '123 Market Street',
            'phone' => '555-0000',
            'is_active' => true,
        ]);

        $user->forceFill(['store_id' => $store->id])->save();

        $this->withHeaders([
            'X-Tenant' => $user->tenant?->slug,
            'X-Store' => $store->id,
        ]);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $res = $this->getJson("/api/reports/summary?from={$from}&to={$to}");
        $res->assertStatus(200)
            ->assertJsonStructure([
                'orders_count', 'gross', 'discount', 'tax', 'refunds', 'net'
            ]);
    }
}
