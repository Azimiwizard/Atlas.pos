<?php

namespace App\Services;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StockService
{
    public function __construct(
        protected TenantManager $tenants
    ) {
    }

    public function getOnHand(string $variantId, string $storeId): float
    {
        if ($storeId === '') {
            throw new InvalidArgumentException('Store id is required to query stock levels.');
        }

        $tenantId = $this->tenants->id();
        if ($tenantId === null) {
            throw new InvalidArgumentException('Tenant context is required to query stock levels.');
        }

        $stock = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('variant_id', $variantId)
            ->where('store_id', $storeId)
            ->first();

        return (float) ($stock?->qty_on_hand ?? 0);
    }

    public function adjust(
        ?string $variantId,
        string $storeId,
        float $delta,
        string $reason,
        ?array $reference = null,
        ?string $userId = null,
        ?string $note = null,
        ?Product $product = null
    ): float {
        if ($storeId === '') {
            throw new InvalidArgumentException('Store id is required to adjust stock.');
        }

        $tenantId = $this->tenants->id();
        if ($tenantId === null) {
            throw new InvalidArgumentException('Tenant context is required to adjust stock.');
        }

        if ($product) {
            $product->loadMissing('variants');
            if (!$variantId || !$product->variants->contains('id', $variantId)) {
                $variantId = $product->ensureDefaultVariant()->id;
            }
        }

        if (!$variantId) {
            throw new InvalidArgumentException('Variant id is required to adjust stock.');
        }

        if (abs($delta) < 0.0001) {
            return $this->getOnHand($variantId, $storeId);
        }

        $variant = Variant::query()
            ->select(['id', 'product_id', 'track_stock'])
            ->with(['product:id,tenant_id,track_stock'])
            ->findOrFail($variantId);

        if (!$variant->product || $variant->product->tenant_id !== $tenantId) {
            throw new InvalidArgumentException('Variant does not belong to the current tenant.');
        }

        $trackingDisabled = !((bool) $variant->track_stock && (bool) $variant->product?->track_stock);
        $allowWhenDisabled = (bool) config('inventory.allow_adjust_when_tracking_disabled', false);

        if ($trackingDisabled) {
            if (!$allowWhenDisabled) {
                Log::notice('Skipping stock adjustment because tracking is disabled.', [
                    'tenant_id' => $tenantId,
                    'variant_id' => $variantId,
                    'store_id' => $storeId,
                    'delta' => $delta,
                    'reason' => $reason,
                ]);

                return $this->getOnHand($variantId, $storeId);
            }

            Log::warning('Adjusting stock while tracking is disabled.', [
                'tenant_id' => $tenantId,
                'variant_id' => $variantId,
                'store_id' => $storeId,
                'delta' => $delta,
                'reason' => $reason,
            ]);
        }

        return DB::transaction(function () use ($tenantId, $variantId, $storeId, $delta, $reason, $reference, $userId, $note) {
            $stock = StockLevel::query()
                ->where('tenant_id', $tenantId)
                ->where('variant_id', $variantId)
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'variant_id' => $variantId,
                        'store_id' => $storeId,
                    ],
                    [
                        'qty_on_hand' => 0,
                    ]
                );

            $newQty = round(((float) $stock->qty_on_hand) + $delta, 3);

            $allowNegative = (bool) config('inventory.allow_negative_stock', false);

            if (!$allowNegative && $newQty < 0) {
                throw ValidationException::withMessages([
                    'qty_delta' => ['This adjustment would reduce stock below zero.'],
                ]);
            }

            $stock->qty_on_hand = $newQty;
            $stock->save();

            InventoryLedger::create([
                'tenant_id' => $tenantId,
                'variant_id' => $variantId,
                'store_id' => $storeId,
                'qty_delta' => round($delta, 3),
                'ref_type' => $reference['type'] ?? null,
                'ref_id' => $reference['id'] ?? null,
                'user_id' => $userId,
                'reason' => $reason,
                'note' => $note,
            ]);

            return $newQty;
        });
    }
}

