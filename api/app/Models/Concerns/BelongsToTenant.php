<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    /**
     * Boot the trait and register the global scope & creating hook.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $tenantId = app(TenantManager::class)->id();

            if ($tenantId && empty($model->tenant_id)) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    /**
     * Scope a query to a specific tenant id.
     */
    public function scopeForTenant(Builder $builder, string|int $tenantId): Builder
    {
        return $builder->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn('tenant_id'), (string) $tenantId);
    }
}
