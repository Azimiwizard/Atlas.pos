<?php

namespace Tests\Feature\Backoffice;

use App\Jobs\GenerateFinanceExport;
use App\Models\FinanceExport;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceExportJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_export_csv_dispatches_job(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['slug' => 'export-tenant']);
        $store = Store::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'store_id' => $store->id,
        ]);

        Sanctum::actingAs($user, ['*'], 'sanctum');

        $query = http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-31',
            'currency' => 'USD',
            'store_id' => $store->id,
        ]);

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/export.csv?'.$query);

        $response->assertStatus(202)
            ->assertJsonStructure(['job_id', 'status_url']);

        Bus::assertDispatched(GenerateFinanceExport::class);
    }

    public function test_status_endpoint_returns_signed_url(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'status-tenant']);
        $store = Store::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'store_id' => $store->id,
        ]);

        Sanctum::actingAs($user, ['*'], 'sanctum');
        Storage::fake('local');

        $export = FinanceExport::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'csv',
            'status' => 'completed',
            'path' => 'exports/test.csv',
            'available_until' => now()->addDay(),
            'options' => [
                'filters' => [
                    'tenant_id' => $tenant->id,
                    'date_from' => '2025-01-01',
                    'date_to' => '2025-01-31',
                    'timezone' => 'UTC',
                    'store_id' => $store->id,
                    'currency' => 'USD',
                    'limit' => 10,
                    'bucket' => 'day',
                ],
            ],
        ]);

        Storage::disk('local')->put('exports/test.csv', 'csv-data');

        $response = $this->withHeaders([
            'X-Tenant' => $tenant->slug,
            'X-Store' => $store->id,
        ])->getJson('/api/bo/finance/export/status/'.$export->id);

        $response->assertOk()
            ->assertJsonStructure(['download_url']);

        $downloadUrl = $response->json('download_url');
        $downloadResponse = $this->get($downloadUrl);
        $downloadResponse->assertOk();
        $this->assertSame('csv-data', $downloadResponse->streamedContent());
    }
}
