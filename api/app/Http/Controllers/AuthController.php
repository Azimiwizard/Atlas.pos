<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected TenantManager $tenantManager)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'tenant.name' => ['required', 'string', 'max:255'],
            'tenant.slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:tenants,slug'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tenant = Tenant::create([
            'name' => $payload['tenant']['name'],
            'slug' => $payload['tenant']['slug'],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => $payload['password'],
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'tenant' => $tenant,
            'user' => $user->load('tenant'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $singleTenant = (bool) config('tenancy.single_tenant');

        $rules = [
            'tenant' => ['nullable', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];

        $credentials = $request->validate($rules);

        try {
            $tenant = $singleTenant
                ? $this->tenantManager->ensure()
                : $this->resolveTenantForMultiTenant($credentials['tenant'] ?? null, $request);
        } catch (\Throwable $exception) {
            $this->tenantManager->forget();

            throw ValidationException::withMessages([
                'tenant' => ['Selected tenant not found or inactive.'],
            ]);
        }

        $user = User::where('tenant_id', $tenant->id)
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->tenantManager->forget();

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        $this->tenantManager->forget();

        return response()->json([
            'tenant' => $tenant,
            'user' => $user->load(['tenant', 'store']),
            'token' => $token,
        ]);
    }

    protected function resolveTenantForMultiTenant(?string $identifier, Request $request): Tenant
    {
        $tenant = null;

        if (!empty($identifier)) {
            $identifier = trim($identifier);
            $tenant = Tenant::query()
                ->where(function ($query) use ($identifier) {
                    $query->where('slug', $identifier);

                    if (Str::isUuid($identifier)) {
                        $query->orWhere('id', $identifier);
                    }
                })
                ->where('is_active', true)
                ->first();
        } elseif (app()->environment('local')) {
            $tenant = Tenant::query()
                ->where('slug', 'default')
                ->where('is_active', true)
                ->first()
                ?? Tenant::query()
                    ->where('is_active', true)
                    ->orderBy('created_at')
                    ->first();

            if ($tenant) {
                Log::warning('tenancy.auto_default', [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                ]);
            }
        }

        if (!$tenant) {
            Log::warning('login.tenant_lookup_failed', [
                'identifier' => $identifier,
            ]);
            throw ValidationException::withMessages([
                'tenant' => ['Selected tenant not found or inactive.'],
            ]);
        }

        $this->tenantManager->setTenant($tenant);
        $request->attributes->set('tenant_id', $tenant->id);

        return $tenant;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['tenant', 'store']);
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant' => $user->tenant,
                'store' => $user->store,
                'store_id' => $user->store_id,
            ],
        ]);
    }
}
