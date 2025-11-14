<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantManager;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenant
{
    public function __construct(protected TenantManager $tenantManager)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (config('tenancy.single_tenant')) {
                $tenant = $this->tenantManager->ensure();
                $request->attributes->set('tenant', $tenant);

                return $next($request);
            }

            $tenantIdentifier = $request->header('X-Tenant', $request->query('tenant'));

            if (!$tenantIdentifier && app()->environment('local')) {
                $autoTenant = Tenant::query()
                    ->where('slug', 'default')
                    ->where('is_active', true)
                    ->first()
                    ?? Tenant::query()
                        ->where('is_active', true)
                        ->orderBy('created_at')
                        ->first();

                if ($autoTenant) {
                    $tenantIdentifier = $autoTenant->slug;
                    Log::warning('tenancy.auto_default', [
                        'tenant_id' => $autoTenant->id,
                        'tenant_slug' => $autoTenant->slug,
                        'path' => $request->path(),
                    ]);
                }
            }

            if (!$tenantIdentifier) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Selected tenant not found or inactive.');
            }

            $tenant = $this->tenantManager->ensure($tenantIdentifier);
            $request->attributes->set('tenant', $tenant);
            $request->attributes->set('tenant_id', $tenant->id);

            return $next($request);
        } catch (ModelNotFoundException|InvalidArgumentException $exception) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Selected tenant not found or inactive.');
        } finally {
            $this->tenantManager->forget();
        }
    }
}
