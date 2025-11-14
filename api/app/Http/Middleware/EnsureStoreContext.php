<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\StoreManager;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureStoreContext
{
    public function __construct(
        protected StoreManager $stores,
        protected TenantManager $tenants
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $user = $request->user();
            $tenantId = $user?->tenant_id ?? $this->tenants->id();

            if (!$tenantId) {
                throw new HttpException(500, 'Tenant context missing before resolving store.');
            }

            $storeId = $request->headers->get('X-Store')
                ?? $request->get('store_id')
                ?? ($request->hasSession() ? $request->session()->get('store_id') : null);

            if (!$storeId && $user?->store_id) {
                $storeId = $user->store_id;
            }

            $storeQuery = Store::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true);

            if ($storeId) {
                $store = $storeQuery->where('id', $storeId)->first();

                if (!$store) {
                    throw new HttpException(403, 'Store is not available for this tenant.');
                }
            } else {
                $store = $storeQuery->orderBy('name')->first();

                if ($store && app()->environment('local')) {
                    Log::warning('tenancy.store_auto_default', [
                        'store_id' => $store->id,
                        'store_name' => $store->name,
                        'path' => $request->path(),
                    ]);
                }

                if (!$store) {
                    throw new HttpException(422, 'No active stores configured for this tenant.');
                }
            }

            if ($user && $user->role === 'cashier' && $user->store_id !== $store->id) {
                throw new HttpException(403, 'Cashiers can only operate in their assigned store.');
            }

            $this->stores->set($store);
            $request->attributes->set('store', $store);
            $request->attributes->set('store_id', $store->id);

            if ($request->hasSession()) {
                $request->session()->put('store_id', $store->id);
            }

            return $next($request);
        } finally {
            $this->stores->forget();
        }
    }
}
