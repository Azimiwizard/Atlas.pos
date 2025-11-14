<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustRequest;
use App\Http\Requests\StockListRequest;
use App\Http\Resources\StockLevelResource;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\Variant;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function __construct(
        protected StockService $stockService
    ) {
    }

    public function index(StockListRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = $request->user()->tenant_id;

        $query = StockLevel::query()
            ->with([
                'store:id,name,code',
                'variant:id,product_id,name,sku',
                'variant.product:id,title',
            ])
            ->where('tenant_id', $tenantId);

        if (!empty($validated['variant_id'])) {
            $query->where('variant_id', $validated['variant_id']);
        }

        if (!empty($validated['product_id'])) {
            $productId = $validated['product_id'];
            $query->whereHas('variant', function ($builder) use ($productId) {
                $builder->where('product_id', $productId);
            });
        }

        if (!empty($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

        $levels = $query
            ->orderBy('store_id')
            ->orderBy('variant_id')
            ->get();

        return StockLevelResource::collection($levels)->response();
    }

    public function adjust(StockAdjustRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $tenantId = $user->tenant_id;

        [$product, $variant] = $this->resolveProductAndVariant($tenantId, $data);

        $trackingDisabled = !((bool) $variant->track_stock && (bool) $product->track_stock);
        $allowWhenDisabled = (bool) config('inventory.allow_adjust_when_tracking_disabled', false);

        if ($trackingDisabled && !$allowWhenDisabled) {
            throw ValidationException::withMessages([
                'variant_id' => ['Stock tracking is disabled for this product.'],
            ]);
        }

        $store = Store::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($data['store_id']);

        $newQty = $this->stockService->adjust(
            $variant->id,
            $store->id,
            (float) $data['qty_delta'],
            $data['reason'],
            reference: [
                'type' => 'stock_adjustment',
                'id' => $product->id,
            ],
            userId: $user->id,
            note: $data['note'] ?? null,
            product: $product
        );

        $stock = StockLevel::query()
            ->with([
                'store:id,name,code',
                'variant:id,product_id,name,sku',
            ])
            ->where('tenant_id', $tenantId)
            ->where('variant_id', $variant->id)
            ->where('store_id', $store->id)
            ->first();

        if (!$stock) {
            $stock = new StockLevel([
                'tenant_id' => $tenantId,
                'variant_id' => $variant->id,
                'store_id' => $store->id,
                'qty_on_hand' => $newQty,
            ]);
            $stock->setRelation('variant', $variant->loadMissing('product'));
            $stock->setRelation('store', $store);
        }

        return (new StockLevelResource($stock))->response();
    }

    /**
     * Resolve both product and variant context based on the provided payload.
     *
     * @param array<string, mixed> $payload
     * @return array{0: Product, 1: Variant}
     */
    protected function resolveProductAndVariant(string $tenantId, array $payload): array
    {
        $variantId = $payload['variant_id'] ?? null;
        $productId = $payload['product_id'] ?? null;

        if ($variantId) {
            $variant = Variant::query()
                ->with([
                    'product' => fn ($query) => $query->where('tenant_id', $tenantId),
                ])
                ->find($variantId);

            if (!$variant?->product) {
                throw ValidationException::withMessages([
                    'variant_id' => ['Variant could not be found for this tenant.'],
                ]);
            }

            if ($productId && $variant->product->id !== $productId) {
                throw ValidationException::withMessages([
                    'variant_id' => ['Variant does not belong to this product.'],
                ]);
            }

            return [$variant->product, $variant];
        }

        if (!$productId) {
            throw ValidationException::withMessages([
                'variant_id' => ['Provide either a product or variant to adjust stock.'],
            ]);
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->with('variants')
            ->findOrFail($productId);

        $defaultVariant = $product->ensureDefaultVariant()->loadMissing('product');
        $product->load('variants');

        if ($product->variants->count() > 1) {
            throw ValidationException::withMessages([
                'variant_id' => ['Product has multiple variants; choose one.'],
            ]);
        }

        $variant = $product->variants->first() ?? $defaultVariant;

        return [$product, $variant->loadMissing('product')];
    }
}
