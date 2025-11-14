<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\UsesUuid;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;
    use BelongsToTenant;
    use UsesUuid;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'category',
        'amount',
        'incurred_at',
        'vendor',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'incurred_at' => 'datetime',
    ];

    protected static function newFactory(): ExpenseFactory
    {
        return ExpenseFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForStoreAccess(Builder $query, User $user): Builder
    {
        if (!$user->store_id) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($user) {
            $builder
                ->whereNull($builder->qualifyColumn('store_id'))
                ->orWhere($builder->qualifyColumn('store_id'), $user->store_id);
        });
    }
}
