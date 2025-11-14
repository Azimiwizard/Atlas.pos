<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private TenantManager $tenantManager)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'menu_category_id' => ['nullable', 'uuid'],
            'is_active' => ['nullable', 'in:true,false,1,0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort' => ['nullable', 'string', 'max:64'],
            'include' => ['nullable', 'string', 'max:255'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $perPage = max(1, min($perPage, 100));

        [$sortField, $sortDirection] = $this->parseSort($validated['sort'] ?? null);

        $includes = $this->parseInclude($validated['include'] ?? null);

        $with = $this->indexRelations();
        foreach ($this->optionGroupLoaders($includes) as $relation => $callback) {
            $with[$relation] = $callback;
        }

        $query = Product::query()->with($with);

        if (!empty($validated['q'])) {
            $search = trim((string) $validated['q']);
            $operator = $this->likeOperator();

            $query->where(function ($builder) use ($operator, $search) {
                $builder
                    ->where('title', $operator, "%{$search}%")
                    ->orWhere('sku', $operator, "%{$search}%")
                    ->orWhere('barcode', $operator, "%{$search}%");
            });
        }

        if (!empty($validated['menu_category_id'])) {
            $query->where('menu_category_id', $validated['menu_category_id']);
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', in_array((string) $validated['is_active'], ['true', '1'], true));
        }

        $query->orderBy($sortField, $sortDirection);

        $paginator = $query
            ->paginate($perPage)
            ->appends($request->query());

        return ProductResource::collection($paginator)->response();
    }

    public function store(ProductStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['category_id']);
        $tenantId = $this->tenantManager->id();

        if (!$tenantId) {
            throw ValidationException::withMessages([
                'tenant' => ['Unable to resolve tenant context.'],
            ]);
        }

        $data['tenant_id'] = $tenantId;
        $data['track_stock'] = array_key_exists('track_stock', $data)
            ? (bool) $data['track_stock']
            : true;
        $data['is_active'] = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : true;

        $includes = $this->parseInclude($request->query('include'));

        $product = Product::create($data);
        $product->ensureDefaultVariant();

        $relations = $this->showRelations();
        foreach ($this->optionGroupLoaders($includes) as $relation => $callback) {
            $relations[$relation] = $callback;
        }

        return (new ProductResource($product->load($relations)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensureProductTenant($product);

        $includes = $this->parseInclude($request->query('include'));
        $product->ensureDefaultVariant();

        $relations = $this->showRelations();
        foreach ($this->optionGroupLoaders($includes) as $relation => $callback) {
            $relations[$relation] = $callback;
        }

        return (new ProductResource($product->load($relations)))->response();
    }

    public function update(ProductUpdateRequest $request, Product $product): JsonResponse
    {
        $this->ensureProductTenant($product);

        $data = $request->validated();
        unset($data['category_id']);

        if (array_key_exists('track_stock', $data) && $data['track_stock'] !== null) {
            $data['track_stock'] = (bool) $data['track_stock'];
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        $product->fill($data)->save();
        $product->refresh();
        $product->ensureDefaultVariant();

        $includes = $this->parseInclude($request->query('include'));

        $relations = $this->showRelations();
        foreach ($this->optionGroupLoaders($includes) as $relation => $callback) {
            $relations[$relation] = $callback;
        }

        return (new ProductResource($product->load($relations)))->response();
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->ensureProductTenant($product);
        $product->delete();

        return response()->json([], 204);
    }

    protected function parseSort(?string $sort): array
    {
        $default = ['created_at', 'desc'];

        if (!$sort) {
            return $default;
        }

        $parts = array_map('trim', explode(':', $sort));
        $field = $parts[0] ?? $default[0];
        $direction = $parts[1] ?? $default[1];

        $allowedFields = ['created_at', 'title', 'price', 'is_active'];
        $allowedDirections = ['asc', 'desc'];

        if (!in_array($field, $allowedFields, true)) {
            $field = $default[0];
        }

        if (!in_array(strtolower($direction), $allowedDirections, true)) {
            $direction = $default[1];
        }

        return [$field, strtolower($direction)];
    }

    protected function likeOperator(): string
    {
        $driver = Product::query()->getConnection()->getDriverName();

        return $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    protected function ensureProductTenant(Product $product): void
    {
        $tenantId = $this->tenantManager->id();

        if (!$tenantId || $product->tenant_id !== $tenantId) {
            abort(404);
        }
    }

    /**
     * @return string[]
     */
    protected function parseInclude(?string $include): array
    {
        if (!$include) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $include)));
    }

    /**
     * @return array<int, string>
     */
    protected function indexRelations(): array
    {
        return ['menuCategory', 'stockLevels.store', 'stockLevels.variant'];
    }

    /**
     * @return array<int, string>
     */
    protected function showRelations(): array
    {
        return ['menuCategory', 'stockLevels.store', 'stockLevels.variant', 'variants'];
    }

    /**
     * @param array<int, string> $includes
     *
     * @return array<string, callable>
     */
    protected function optionGroupLoaders(array $includes): array
    {
        if (!$this->shouldIncludeOptionGroups($includes)) {
            return [];
        }

        $loadOptions = $this->shouldIncludeGroupOptions($includes);

        return [
            'optionGroups' => function ($builder) use ($loadOptions) {
                $builder->orderBy('sort_order');

                if ($loadOptions) {
                    $builder->with([
                        'options' => fn ($query) => $query->orderBy('sort_order'),
                    ]);
                }
            },
        ];
    }

    /**
     * @param array<int, string> $includes
     */
    protected function shouldIncludeOptionGroups(array $includes): bool
    {
        return in_array('option_groups', $includes, true)
            || in_array('option-groups', $includes, true)
            || $this->shouldIncludeGroupOptions($includes);
    }

    /**
     * @param array<int, string> $includes
     */
    protected function shouldIncludeGroupOptions(array $includes): bool
    {
        return in_array('option_groups.options', $includes, true)
            || in_array('option-groups.options', $includes, true)
            || in_array('options', $includes, true);
    }
}
