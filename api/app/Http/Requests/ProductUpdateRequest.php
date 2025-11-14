<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Services\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        if ($product instanceof Product) {
            return $this->user()?->can('update', $product) ?? false;
        }

        return $this->user()?->can('update', Product::class) ?? false;
    }

    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');
        $tenantId = app(TenantManager::class)->id();
        $productId = $product?->id;

        $menuCategoryRule = Rule::exists('menu_categories', 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $skuRule = Rule::unique('products', 'sku')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId))
            ->ignore($productId);

        $barcodeRule = Rule::unique('products', 'barcode')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId))
            ->ignore($productId);

        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'menu_category_id' => ['sometimes', 'nullable', 'uuid', $menuCategoryRule],
            'category_id' => ['sometimes', 'nullable', 'uuid', $menuCategoryRule],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', $skuRule],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:128', $barcodeRule],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tax_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'track_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(
            array_merge(
                $this->coerceBooleanFields(),
                $this->normalizeCategoryAlias()
            )
        );
    }

    /**
     * Convert incoming boolean-like values to actual booleans.
     *
     * @return array<string, bool|null>
     */
    protected function coerceBooleanFields(): array
    {
        $converted = [];

        foreach (['track_stock', 'is_active'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($normalized !== null) {
                $converted[$field] = $normalized;
            }
        }

        return $converted;
    }

    /**
     * Normalize legacy category inputs so only menu_category_id is stored.
     *
     * @return array<string, string|null>
     */
    protected function normalizeCategoryAlias(): array
    {
        $hasMenuCategory = $this->filled('menu_category_id');
        $legacyId = $this->input('category_id');

        if ($hasMenuCategory || empty($legacyId)) {
            return [];
        }

        $this->ensureLegacyMenuCategoryExists((string) $legacyId);

        return [
            'menu_category_id' => (string) $legacyId,
        ];
    }

    protected function ensureLegacyMenuCategoryExists(string $legacyId): void
    {
        if (MenuCategory::query()->whereKey($legacyId)->exists()) {
            return;
        }

        $tenantId = app(TenantManager::class)->id() ?? $this->user()?->tenant_id;

        $legacyCategory = Category::query()
            ->whereKey($legacyId)
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();

        if (! $legacyCategory) {
            return;
        }

        MenuCategory::unguarded(function () use ($legacyCategory): void {
            MenuCategory::query()->updateOrCreate(
                ['id' => $legacyCategory->id],
                [
                    'tenant_id' => $legacyCategory->tenant_id,
                    'name' => $legacyCategory->name,
                    'sort_order' => (int) ($legacyCategory->sort_order ?? 0),
                    'is_active' => (bool) $legacyCategory->is_active,
                ]
            );
        });
    }
}
