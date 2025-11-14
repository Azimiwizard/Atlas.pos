<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\UsesUuid;
use App\Models\MenuCategory;
use App\Models\OptionGroup;
use App\Models\Tax;
use App\Models\Variant;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasFactory;
    use BelongsToTenant;
    use UsesUuid;

    protected $fillable = [
        'tenant_id',
        'title',
        'menu_category_id',
        'sku',
        'barcode',
        'price',
        'tax_code',
        'track_stock',
        'image_url',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $appends = [
        'first_variant_price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'track_stock' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'price' => 0,
        'track_stock' => true,
        'is_active' => true,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function menuCategory(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function setMenuCategoryIdAttribute(?string $value): void
    {
        $this->attributes['menu_category_id'] = $value;
        $this->attributes['category_id'] = $value;
    }

    public function getCategoryIdAttribute(?string $value): ?string
    {
        return $this->attributes['menu_category_id'] ?? $value;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class)->orderBy('sort_order');
    }


    public function stockLevels(): HasManyThrough
    {
        return $this->hasManyThrough(
            StockLevel::class,
            Variant::class,
            'product_id',
            'variant_id',
            'id',
            'id'
        );
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category')->withTimestamps();
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'product_tax')
            ->withTimestamps();
    }

    public function getFirstVariantPriceAttribute(): ?string
    {
        $variant = $this->relationLoaded('variants')
            ? $this->variants->first()
            : $this->variants()->first();

        return $variant?->price !== null ? (string) $variant->price : null;
    }

    /**
     * Ensure the product has a default variant and return it.
     */
    public function ensureDefaultVariant(): Variant
    {
        $refreshVariantsRelation = function () {
            if ($this->relationLoaded('variants')) {
                $this->setRelation('variants', $this->variants()->get());
            }
        };

        $existingDefault = $this->variants()
            ->where('is_default', true)
            ->first();

        if ($existingDefault) {
            $refreshVariantsRelation();

            return $existingDefault;
        }

        $firstVariant = $this->variants()
            ->orderBy('created_at')
            ->first();

        if ($firstVariant) {
            $payload = ['is_default' => true];

            if ($this->barcode && !$firstVariant->barcode) {
                $payload['barcode'] = $this->barcode;
            }

            if (!$firstVariant->is_default || array_key_exists('barcode', $payload)) {
                $firstVariant->forceFill($payload)->save();
            }

            $refreshVariantsRelation();

            return $firstVariant->fresh();
        }

        $variant = $this->variants()->create([
            'name' => $this->title,
            'sku' => null,
            'barcode' => $this->barcode,
            'price' => $this->price !== null ? $this->price : 0,
            'cost' => null,
            'track_stock' => (bool) $this->track_stock,
            'is_default' => true,
        ]);

        $refreshVariantsRelation();

        return $variant;
    }
}


