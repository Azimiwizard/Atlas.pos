<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Services\StoreManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private StoreManager $stores)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $tenantId = (string) auth()->user()->tenant_id;
        $search = trim((string) $request->query('search', ''));
        $hasSearch = $search !== '';
        $categoryFilter = (string) $request->query('category_id', '');
        $storeId = $this->stores->id() ?? $request->user()->store_id;

        $query = Product::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'variants' => fn ($builder) => $builder
                    ->orderBy('created_at')
                    ->with(['stockLevels' => function ($query) use ($storeId) {
                        if ($storeId) {
                            $query->where('store_id', $storeId);
                        }
                    }]),
                'menuCategory:id,name',
                'taxes:id,name,rate,inclusive',
                'optionGroups.options',
            ]);

        if ($hasSearch) {
            $operator = $this->likeOperator();

            $query->where(function (Builder $builder) use ($search, $operator) {
                $builder
                    ->where('title', $operator, "%{$search}%")
                    ->orWhere('barcode', $operator, "%{$search}%");
            })->orderBy('title');
        } else {
            $query->latest();
        }

        if ($categoryFilter !== '') {
            $query->whereHas('menuCategory', fn ($builder) => $builder->where('menu_categories.id', $categoryFilter));
        }

        return response()->json(
            $query->paginate($perPage)->appends($request->query())
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (string) auth()->user()->tenant_id;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'barcode')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $data['is_active'] ?? true;
        $data['tenant_id'] = $tenantId;

        $product = Product::create($data);

        return response()->json(
            $product->fresh([
                'variants',
                'menuCategory:id,name',
                'taxes:id,name,rate,inclusive',
                'optionGroups.options',
            ]),
            201
        );
    }

    public function show(Product $product): JsonResponse
    {
        $this->ensureProductBelongsToTenant($product);

        $storeId = $this->stores->id() ?? auth()->user()?->store_id;

        return response()->json(
            $product->load([
                'variants' => fn ($builder) => $builder->with(['stockLevels' => function ($query) use ($storeId) {
                    if ($storeId) {
                        $query->where('store_id', $storeId);
                    }
                }]),
                'menuCategory:id,name',
                'taxes:id,name,rate,inclusive',
                'optionGroups.options',
            ])
        );
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->ensureProductBelongsToTenant($product);

        $tenantId = (string) auth()->user()->tenant_id;

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'barcode')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($product->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $product->fill($data)->save();

        return response()->json(
            $product->fresh([
                'variants' => fn ($builder) => $builder->with('stockLevels'),
                'menuCategory:id,name',
                'taxes:id,name,rate,inclusive',
                'optionGroups.options',
            ])
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->ensureProductBelongsToTenant($product);

        $product->delete();

        return response()->json([], 204);
    }

    public function updateMenuCategory(Request $request, Product $product): JsonResponse
    {
        $this->ensureProductBelongsToTenant($product);

        $data = $request->validate([
            'menu_category_id' => ['nullable', 'string'],
        ]);

        if ($data['menu_category_id']) {
            $category = MenuCategory::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('id', $data['menu_category_id'])
                ->first();

            if (!$category) {
                throw ValidationException::withMessages([
                    'menu_category_id' => ['The selected menu category is invalid.'],
                ]);
            }
        }

        $product->update([
            'menu_category_id' => $data['menu_category_id'],
        ]);

        return response()->json(
            $product->fresh([
                'menuCategory:id,name',
                'taxes:id,name,rate,inclusive',
                'variants' => fn ($builder) => $builder->with('stockLevels'),
                'optionGroups.options',
            ])
        );
    }

    public function syncTaxes(Request $request, Product $product): JsonResponse
    {
        $this->ensureProductBelongsToTenant($product);

        $data = $request->validate([
            'tax_ids' => ['array'],
            'tax_ids.*' => ['string'],
        ]);

        $ids = array_unique($data['tax_ids'] ?? []);

        if (!empty($ids)) {
            $count = Tax::query()
                ->where('tenant_id', $product->tenant_id)
                ->whereIn('id', $ids)
                ->count();

            if ($count !== count($ids)) {
                throw ValidationException::withMessages([
                    'tax_ids' => ['One or more taxes are invalid.'],
                ]);
            }
        }

        $product->taxes()->sync($ids);

        return response()->json(
            $product->fresh([
                'menuCategory:id,name',
                'taxes:id,name,rate,inclusive',
                'variants',
                'optionGroups.options',
            ])
        );
    }

    protected function likeOperator(): string
    {
        return Product::query()->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    protected function ensureProductBelongsToTenant(Product $product): void
    {
        $tenantId = (string) auth()->user()->tenant_id;

        if ($product->tenant_id !== $tenantId) {
            abort(404);
        }
    }
}
