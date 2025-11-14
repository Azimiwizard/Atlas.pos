<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class PublicTenantController extends Controller
{
    public function presets(): JsonResponse
    {
        $tenants = Tenant::query()
            ->select(['slug', 'name'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (Tenant $tenant) => [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ])
            ->values();

        return response()->json($tenants);
    }
}

