<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\OptionGroupStoreRequest;
use App\Http\Requests\OptionGroupUpdateRequest;
use App\Http\Resources\OptionGroupResource;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptionGroupController extends Controller
{
    public function __construct(private TenantManager $tenantManager)
    {
    }

    public function index(Request $request, Product $product): JsonResponse
    {
        $product = $this->ensureProductTenant($product);
        $includes = $this->parseInclude($request->query('include'));

        $query = $product->optionGroups()->newQuery()->orderBy('sort_order');

        if (in_array('options', $includes, true)) {
            $query->with(['options' => fn ($builder) => $builder->orderBy('sort_order')]);
        }

        $groups = $query->get();

        return OptionGroupResource::collection($groups)->response();
    }

    public function store(OptionGroupStoreRequest $request, Product $product): JsonResponse
    {
        $product = $this->ensureProductTenant($product);
        $data = $request->validated();

        $nextSort = (int) ($product->optionGroups()->max('sort_order') ?? 0) + 1;
        $max = $data['max'] ?? ($data['selection_type'] === 'single' ? 1 : null);
        if ($data['selection_type'] === 'single') {
            $max = 1;
        }

        $group = $product->optionGroups()->create([
            'tenant_id' => $product->tenant_id,
            'name' => $data['name'],
            'selection_type' => $data['selection_type'],
            'min' => $data['min'] ?? 0,
            'max' => $max,
            'sort_order' => $data['sort_order'] ?? $nextSort,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        $group->load('options');

        return (new OptionGroupResource($group))->response()->setStatusCode(201);
    }

    public function show(Request $request, Product $product, OptionGroup $optionGroup): JsonResponse
    {
        $product = $this->ensureProductTenant($product);
        $this->ensureGroupBelongsToProduct($optionGroup, $product);

        $includes = $this->parseInclude($request->query('include'));

        if (in_array('options', $includes, true)) {
            $optionGroup->load(['options' => fn ($builder) => $builder->orderBy('sort_order')]);
        }

        return (new OptionGroupResource($optionGroup))->response();
    }

    public function update(
        OptionGroupUpdateRequest $request,
        Product $product,
        OptionGroup $optionGroup
    ): JsonResponse {
        $product = $this->ensureProductTenant($product);
        $this->ensureGroupBelongsToProduct($optionGroup, $product);

        $data = $request->validated();

        $max = $data['max'] ?? ($data['selection_type'] === 'single' ? 1 : $optionGroup->max);
        if ($data['selection_type'] === 'single') {
            $max = 1;
        }

        $optionGroup->fill([
            'name' => $data['name'],
            'selection_type' => $data['selection_type'],
            'min' => $data['min'] ?? $optionGroup->min,
            'max' => $max,
            'sort_order' => $data['sort_order'] ?? $optionGroup->sort_order,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $optionGroup->is_active,
        ])->save();

        $optionGroup->load(['options' => fn ($builder) => $builder->orderBy('sort_order')]);

        return (new OptionGroupResource($optionGroup))->response();
    }

    public function destroy(Product $product, OptionGroup $optionGroup): JsonResponse
    {
        $product = $this->ensureProductTenant($product);
        $this->ensureGroupBelongsToProduct($optionGroup, $product);

        $optionGroup->delete();

        return response()->json([], 204);
    }

    protected function ensureProductTenant(Product $product): Product
    {
        $tenantId = $this->tenantManager->id();

        if (!$tenantId || $product->tenant_id !== $tenantId) {
            abort(404);
        }

        return $product;
    }

    protected function ensureGroupBelongsToProduct(OptionGroup $group, Product $product): void
    {
        if ($group->product_id !== $product->id) {
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
}
