<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{
    public function index(Request $request, TenantManager $tenantManager): JsonResponse
    {
        $request->validate([
            'is_active' => ['nullable', 'in:1,0,true,false'],
        ]);

        $tenantId = $tenantManager->id() ?? (string) $request->user()->tenant_id;

        $query = Store::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name');

        if ($request->filled('is_active')) {
            $raw = $request->query('is_active');
            $active = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active !== null) {
                $query->where('is_active', $active);
            }
        }

        return response()->json($query->get());
    }

    public function store(Request $request, TenantManager $tenantManager): JsonResponse
    {
        $tenantId = $tenantManager->id() ?? (string) $request->user()->tenant_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('stores')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $store = Store::create(array_merge($data, [
            'tenant_id' => $tenantId,
        ]));

        $this->flushStoreCache($tenantId);

        return response()->json($store, 201);
    }

    public function update(Request $request, Store $store, TenantManager $tenantManager): JsonResponse
    {
        $tenantId = $tenantManager->id() ?? (string) $request->user()->tenant_id;

        abort_unless($store->tenant_id === $tenantId, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('stores')
                    ->ignore($store->id)
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $store->fill($data);
        $store->save();

        $this->flushStoreCache($tenantId);

        return response()->json($store);
    }

    public function destroy(Request $request, Store $store, TenantManager $tenantManager): JsonResponse
    {
        $tenantId = $tenantManager->id() ?? (string) $request->user()->tenant_id;
        abort_unless($store->tenant_id === $tenantId, 404);

        $store->is_active = false;
        $store->save();

        $this->flushStoreCache($tenantId);

        return response()->json([
            'message' => 'Store deactivated.',
        ]);
    }

    protected function flushStoreCache(string $tenantId): void
    {
        $cache = Cache::getStore();

        if (method_exists($cache, 'tags')) {
            Cache::tags(["tenant:{$tenantId}", 'stores'])->flush();
            return;
        }

        Cache::forget("tenant:{$tenantId}:stores");
    }
}
