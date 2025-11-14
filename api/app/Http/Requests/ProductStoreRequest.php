<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Services\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->id();

        $menuCategoryRule = Rule::exists('menu_categories', 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $skuRule = Rule::unique('products', 'sku')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $barcodeRule = Rule::unique('products', 'barcode')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        return [
            'title' => ['required', 'string', 'max:160'],
            'menu_category_id' => ['nullable', 'uuid', $menuCategoryRule],
            'category_id' => ['nullable', 'uuid', $menuCategoryRule],
            'sku' => ['nullable', 'string', 'max:64', $skuRule],
            'barcode' => ['nullable', 'string', 'max:128', $barcodeRule],
            'price' => ['nullable', 'numeric', 'min:0'],
            'tax_code' => ['nullable', 'string', 'max:64'],
            'track_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'description' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $mutations = array_merge(
            $this->coerceBooleanFields(),
            $this->normalizeCategoryAlias()
        );

        if (!$this->filled('price')) {
            $mutations['price'] = 0;
        }

        $this->merge($mutations);
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
     * Normalize legacy category_id payloads into menu_category_id.
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
