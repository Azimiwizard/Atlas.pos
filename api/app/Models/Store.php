<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\UsesUuid;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;
    use UsesUuid;
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'address',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Store $store): void {
            if (empty($store->tenant_id)) {
                $tenantId = app(TenantManager::class)->id();

                if (!$tenantId) {
                    throw new \RuntimeException('Cannot create store without a tenant context.');
                }

                $store->tenant_id = $tenantId;
            }

            if (!empty($store->code)) {
                $store->code = strtoupper($store->code);
            }
        });

        static::updating(function (Store $store): void {
            if ($store->isDirty('code') && !empty($store->code)) {
                $store->code = strtoupper($store->code);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
