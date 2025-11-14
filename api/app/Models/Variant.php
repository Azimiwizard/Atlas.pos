<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use App\Services\StoreManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variant extends Model
{
    use HasFactory, UsesUuid;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'price',
        'cost',
        'track_stock',
        'barcode',
        'is_default',
    ];

    protected $appends = [
        'stock_on_hand',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'track_stock' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function getStockOnHandAttribute(): float
    {
        $storeId = app(StoreManager::class)->id() ?? request()->attributes->get('store_id') ?? auth()->user()?->store_id;

        if (!$storeId) {
            return (float) ($this->stockLevels->first()?->qty_on_hand ?? 0);
        }

        $stock = $this->relationLoaded('stockLevels')
            ? $this->stockLevels->firstWhere('store_id', $storeId)
            : $this->stockLevels()->where('store_id', $storeId)->first();

        return (float) ($stock->qty_on_hand ?? 0);
    }
}
