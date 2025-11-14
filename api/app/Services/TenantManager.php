<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TenantManager
{
    /** Current tenant instance (if resolved). */
    protected ?Tenant $tenant = null;

    /** Current tenant id as UUID string (or null). */
    protected ?string $tenantId = null;

    /**
     * Ensure a tenant has been resolved for the current request.
     */
    public function ensure(?string $slug = null): Tenant
    {
        if ($this->tenant instanceof Tenant) {
            return $this->tenant;
        }

        if (config('tenancy.single_tenant')) {
            $defaultSlug = (string) config('tenancy.default_tenant_slug', 'default');

            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => $defaultSlug],
                ['name' => Str::of($defaultSlug)->replace(['-', '_'], ' ')->title()->toString()]
            );

            $this->setTenant($tenant);

            return $tenant;
        }

        if ($slug === null || $slug === '') {
            throw new InvalidArgumentException('Tenant identifier required in multi-tenant mode.');
        }

        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $this->setTenant($tenant);

        return $tenant;
    }

    /**
     * Set the current tenant id directly.
     */
    public function set(?string $tenantId): void
    {
        $this->tenantId = $tenantId;

        if ($tenantId === null) {
            $this->tenant = null;
        }
    }

    /**
     * Set the current tenant using the model instance.
     */
    public function setTenant(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->tenantId = $tenant?->id;
    }

    /**
     * Retrieve the current tenant id.
     */
    public function id(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Retrieve the current tenant model.
     */
    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Clear the scoped tenant information.
     */
    public function forget(): void
    {
        $this->tenant = null;
        $this->tenantId = null;
    }
}
