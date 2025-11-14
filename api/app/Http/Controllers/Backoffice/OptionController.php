<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\OptionStoreRequest;
use App\Http\Requests\OptionUpdateRequest;
use App\Http\Resources\OptionResource;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;

class OptionController extends Controller
{
    public function __construct(private TenantManager $tenantManager)
    {
    }

    public function index(Product $product, OptionGroup $optionGroup): JsonResponse
    {
        $product = $this->ensureProductTenant($product);
        $this->ensureGroupBelongsToProduct($optionGroup, $product);

        $options = $optionGroup->options()->orderBy('sort_order')->get();

        return OptionResource::collection($options)->response();
    }

    public function store(
        OptionStoreRequest $request,
        Product $product,
        OptionGroup $optionGroup
    ): JsonResponse {
        $product = $this->ensureProductTenant($product);
        $this->ensureGroupBelongsToProduct($optionGroup, $product);

        $data = $request->validated();
        $nextSort = (int) ($optionGroup->options()->max('sort_order') ?? 0) + 1;

        $option = $optionGroup->options()->create([
            'tenant_id' => $product->tenant_id,
            'name' => $data['name'],
            'price_delta' => $data['price_delta'] ?? 0,
            'is_default' => array_key_exists('is_default', $data) ? (bool) $data['is_default'] : false,
            'sort_order' => $data['sort_order'] ?? $nextSort,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        if ($option->is_default) {
            $this->ensureSingleDefault($optionGroup, $option->id);
        }

        return (new OptionResource($option))->response()->setStatusCode(201);
    }

    public function show(Product $product, OptionGroup $optionGroup, Option $option): JsonResponse
    {
        $product = $this->ensureProductTenant($product);
        $this->ensureGroupBelongsToProduct($optionGroup, $product);
        $this->ensureOptionBelongsToGroup($option, $optionGroup);

        return (new OptionResource($option))->response();
    }

    public function update(
        OptionUpdateRequest $request,
        Product $product,
        OptionGroup $optionGroup,
        Option $option
    ): JsonResponse {
        $product = $this->ensureProductTenant($product);
        $this->ensureGroupBelongsToProduct($optionGroup, $product);
        $this->ensureOptionBelongsToGroup($option, $optionGroup);

        $data = $request->validated();

        $option->fill([
            'name' => $data['name'],
            'price_delta' => $data['price_delta'] ?? $option->price_delta,
            'is_default' => array_key_exists('is_default', $data)
                ? (bool) $data['is_default']
                : $option->is_default,
            'sort_order' => $data['sort_order'] ?? $option->sort_order,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $option->is_active,
        ])->save();

        if ($option->is_default) {
            $this->ensureSingleDefault($optionGroup, $option->id);
        }

        return (new OptionResource($option->refresh()))->response();
    }

    public function destroy(Product $product, OptionGroup $optionGroup, Option $option): JsonResponse
    {
        $product = $this->ensureProductTenant($product);
        $this->ensureGroupBelongsToProduct($optionGroup, $product);
        $this->ensureOptionBelongsToGroup($option, $optionGroup);

        $option->delete();

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

    protected function ensureOptionBelongsToGroup(Option $option, OptionGroup $group): void
    {
        if ($option->option_group_id !== $group->id) {
            abort(404);
        }
    }

    protected function ensureSingleDefault(OptionGroup $group, string $keepOptionId): void
    {
        if ($group->selection_type !== 'single') {
            return;
        }

        $group->options()
            ->where('id', '!=', $keepOptionId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}

