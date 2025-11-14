<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Customer;
use App\Models\CustomerOrder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Order extends Model
{
    use HasFactory;
    use UsesUuid;
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'cashier_id',
        'shift_id',
        'status',
        'subtotal',
        'tax',
        'discount',
        'manual_discount',
        'total',
        'payment_method',
        'refunded_total',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'manual_discount' => 'decimal:2',
        'total' => 'decimal:2',
        'refunded_total' => 'decimal:2',
    ];

    protected $appends = [
        'tax_breakdown',
        'promotion_breakdown',
        'promotion_discount',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function customerOrders(): HasMany
    {
        return $this->hasMany(CustomerOrder::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_orders')
            ->withTimestamps();
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function getTaxBreakdownAttribute(): array
    {
        return $this->attributes['tax_breakdown'] ?? [];
    }

    public function getPromotionBreakdownAttribute(): array
    {
        return $this->attributes['promotion_breakdown'] ?? [];
    }

    public function getPromotionDiscountAttribute(): float
    {
        return (float) ($this->attributes['promotion_discount'] ?? 0);
    }
}
