<?php

namespace App\Http\Controllers;

use App\Models\MenuCategory;
use App\Models\Product;
use App\Services\StoreManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosMenuController extends Controller
{
    public function __construct(private StoreManager $stores)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $tenantId = (string) $user->tenant_id;

        $storeId = $validated['store_id'] ?? $this->stores->id() ?? $user->store_id;

        $categories = MenuCategory::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order', 'image_url']);

        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with([
                'optionGroups' => fn ($builder) => $builder
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->with([
                        'options' => fn ($query) => $query
                            ->where('is_active', true)
                            ->orderBy('sort_order'),
                    ]),
                'variants' => fn ($builder) => $builder
                    ->orderByDesc('is_default')
                    ->orderBy('created_at')
                    ->with([
                        'stockLevels' => function ($query) use ($storeId) {
                            if ($storeId) {
                                $query->where('store_id', $storeId);
                            }
                        },
                    ]),
            ])
            ->orderBy('title')
            ->get();

        $payload = [
            'categories' => $categories->map(fn (MenuCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'sort_order' => (int) $category->sort_order,
                'image_url' => $category->image_url,
            ])->values(),
            'products' => $products->map(function (Product $product) use ($storeId) {
                $product->ensureDefaultVariant();

                $defaultVariant = $product->variants->first();
                $trackStock = (bool) ($product->track_stock && $defaultVariant?->track_stock);
                $stockOnHand = null;

                if ($trackStock && $defaultVariant) {
                    if ($storeId) {
                        $level = $defaultVariant->stockLevels->firstWhere('store_id', $storeId);
                        $stockOnHand = $level !== null ? (float) $level->qty_on_hand : null;
                    } else {
                        $stockOnHand = null;
                    }
                }

                $optionGroups = $product->optionGroups
                    ->filter(fn ($group) => $group->is_active)
                    ->sortBy('sort_order')
                    ->map(function ($group) {
                        $options = $group->options
                            ->filter(fn ($option) => $option->is_active)
                            ->sortBy('sort_order')
                            ->map(fn ($option) => [
                                'id' => $option->id,
                                'name' => $option->name,
                                'price_delta' => (float) ($option->price_delta ?? 0),
                                'is_default' => (bool) $option->is_default,
                                'sort_order' => (int) ($option->sort_order ?? 0),
                            ])
                            ->values();

                        return [
                            'id' => $group->id,
                            'name' => $group->name,
                            'selection_type' => $group->selection_type,
                            'min' => (int) ($group->min ?? 0),
                            'max' => $group->max !== null ? (int) $group->max : null,
                            'sort_order' => (int) ($group->sort_order ?? 0),
                            'options' => $options,
                        ];
                    })
                    ->values();

                return [
                    'id' => $product->id,
                    'category_id' => $product->category_id,
                    'title' => $product->title,
                    'base_price' => (float) ($product->price ?? 0),
                    'image_url' => $product->image_url,
                    'is_active' => (bool) $product->is_active,
                    'default_variant' => $defaultVariant ? [
                        'id' => $defaultVariant->id,
                        'sku' => $defaultVariant->sku,
                        'price' => (float) ($defaultVariant->price ?? $product->price ?? 0),
                        'track_stock' => $trackStock,
                        'stock_on_hand' => $stockOnHand,
                    ] : null,
                    'option_groups' => $optionGroups,
                ];
            })->values(),
        ];

        return response()->json($payload);
    }
}

