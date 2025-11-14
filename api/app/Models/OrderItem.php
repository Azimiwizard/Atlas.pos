<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\UsesUuid;
use App\Models\LineItemOption;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;
    use UsesUuid;
    use BelongsToTenant;

    protected $fillable = [
        'order_id',
        'variant_id',
        'product_id',
        'tenant_id',
        'qty',
        'unit_price',
        'note',
        'cogs_amount',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'cogs_amount' => 'decimal:2',
    ];

    protected $appends = [
        'line_total',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item): void {
            if (!$item->tenant_id && $item->order_id) {
                $order = $item->relationLoaded('order')
                    ? $item->order
                    : Order::query()->select('tenant_id')->find($item->order_id);

                if ($order?->tenant_id) {
                    $item->tenant_id = $order->tenant_id;
                }
            }

            if (!$item->product_id && $item->variant_id) {
                $variant = $item->relationLoaded('variant')
                    ? $item->variant
                    : Variant::query()->select('product_id')->find($item->variant_id);

                if ($variant?->product_id) {
                    $item->product_id = $variant->product_id;
                }
            }
        });

        static::saving(function (OrderItem $item): void {
            if ($item->isDirty('qty') || $item->isDirty('variant_id') || $item->cogs_amount === null) {
                $item->cogs_amount = self::snapshotCogs($item);
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(LineItemOption::class);
    }

    public function getLineTotalAttribute(): string
    {
        $baseTotal = (float) $this->qty * (float) $this->unit_price;

        $optionsTotal = 0.0;

        $options = $this->relationLoaded('options')
            ? $this->options
            : $this->options()->get();

        foreach ($options as $option) {
            $optionsTotal += (float) $option->price_delta * (float) $this->qty;
        }

        $lineTotal = $baseTotal + $optionsTotal;

        return number_format($lineTotal, 2, '.', '');
    }

    protected static function snapshotCogs(OrderItem $item): float
    {
        $qty = (float) ($item->qty ?? 0);

        if ($qty <= 0) {
            return 0.0;
        }

        $variant = $item->relationLoaded('variant') ? $item->variant : null;

        if (!$variant && $item->variant_id) {
            $variant = Variant::query()->select('id', 'cost')->find($item->variant_id);
        }

        $unitCost = $variant ? (float) ($variant->cost ?? 0) : 0.0;
        $unitCost = max($unitCost, 0.0);

        return round($qty * $unitCost, 2);
    }
}
