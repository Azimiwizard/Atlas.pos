<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Customer extends Model
{
    use HasFactory;
    use BelongsToTenant;
    use UsesUuid;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'name',
        'phone',
        'email',
        'loyalty_points',
        'notes',
    ];

    protected $casts = [
        'loyalty_points' => 'integer',
    ];

    public function customerOrders(): HasMany
    {
        return $this->hasMany(CustomerOrder::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'customer_orders')
            ->withTimestamps();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orderHistory(): HasManyThrough
    {
        return $this->hasManyThrough(
            Order::class,
            CustomerOrder::class,
            'customer_id',
            'id',
            'id',
            'order_id'
        );
    }
}
