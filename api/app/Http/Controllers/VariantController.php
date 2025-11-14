<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Variant;
use App\Services\StoreManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VariantController extends Controller
{
    public function __construct(private StoreManager $stores)
    {
    }

    public function index(string $productId): JsonResponse
    {
        $product = $this->findProduct($productId);

        $storeId = $this->stores->id() ?? auth()->user()?->store_id;

        return response()->json(
            $product->variants()
                ->orderBy('name')
                ->with(['stockLevels' => function ($query) use ($storeId) {
                    if ($storeId) {
                        $query->where('store_id', $storeId);
                    }
                }])
                ->get()
        );
    }

    public function store(Request $request, string $productId): JsonResponse
    {
        $product = $this->findProduct($productId);

        $data = $this->validateVariant($request);
        $data['track_stock'] = $data['track_stock'] ?? true;

        $variant = $product->variants()->create($data);

        return response()->json($variant->load('stockLevels'), 201);
    }

    public function update(Request $request, string $productId, string $variantId): JsonResponse
    {
        $product = $this->findProduct($productId);
        $variant = $this->findVariant($product, $variantId);

        $data = $this->validateVariant($request, $variant);

        $variant->fill($data)->save();

        return response()->json($variant->load('stockLevels'));
    }

    public function destroy(string $productId, string $variantId): JsonResponse
    {
        $product = $this->findProduct($productId);
        $variant = $this->findVariant($product, $variantId);

        $variant->delete();

        return response()->json([], 204);
    }

    protected function findProduct(string $productId): Product
    {
        $tenantId = (string) auth()->user()->tenant_id;

        return Product::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($productId);
    }

    protected function findVariant(Product $product, string $variantId): Variant
    {
        return $product->variants()->whereKey($variantId)->firstOrFail();
    }

    protected function validateVariant(Request $request, ?Variant $variant = null): array
    {
        return $request->validate([
            'sku' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('variants', 'sku')->ignore($variant?->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'price' => [$variant ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
        ]);
    }
}
