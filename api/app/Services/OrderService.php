<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Product;
use App\Models\Option;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        protected DatabaseManager $db,
        protected StockService $stocks,
        protected StoreManager $stores
    ) {
    }

    public function createDraft(User $user, ?string $storeId = null): Order
    {
        $storeId = $storeId ?? $user->store_id ?? $this->stores->id();

        if ($user->role === 'cashier' && $user->store_id && $storeId !== $user->store_id) {
            throw ValidationException::withMessages([
                'store' => 'Cashiers are limited to their assigned store.',
            ]);
        }

        if (!$storeId) {
            throw ValidationException::withMessages([
                'store' => 'Store context is required to start an order.',
            ]);
        }

        $order = Order::create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $storeId,
            'cashier_id' => $user->id,
            'status' => 'draft',
            'manual_discount' => 0,
        ]);

        $extras = $this->recalculateOrder($order);

        return $this->loadOrderRelations($order, $extras);
    }

    public function addItem(string $orderId, string $variantId, float $qty): Order
    {
        return $this->db->transaction(function () use ($orderId, $variantId, $qty) {
            $order = $this->getOrderForTenant($orderId, lock: true);

            if (!in_array($order->status, ['draft', 'paid'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Cannot modify items for this order status.',
                ]);
            }

            $currentStoreId = $this->stores->id();
            if ($currentStoreId && $order->store_id && $order->store_id !== $currentStoreId) {
                throw ValidationException::withMessages([
                    'store' => 'Order is locked to a different store.',
                ]);
            }

            if (!$order->store_id) {
                throw ValidationException::withMessages([
                    'store' => 'Order is missing a store assignment.',
                ]);
            }

            $variant = Variant::query()->with('product')->findOrFail($variantId);

            if ($order->tenant_id !== $variant->product->tenant_id) {
                abort(404);
            }

            $item = $order->items()->where('variant_id', $variantId)->first();

            if ($variant->track_stock && $qty > 0) {
                $available = $this->stocks->getOnHand($variantId, $order->store_id);
                $newQty = ($item?->qty ?? 0) + $qty;

                if ($newQty > $available) {
                    throw ValidationException::withMessages([
                        'qty' => 'Insufficient stock in this store.',
                    ]);
                }
            }

            if ($item) {
                $item->qty = $item->qty + $qty;
                if ($item->qty <= 0) {
                    $item->delete();
                } else {
                    $item->unit_price = $variant->price;
                    $item->cogs_amount = $this->snapshotCogs($variant, (float) $item->qty);
                    $item->setRelation('variant', $variant);
                    $item->save();
                }
            } else {
                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'Quantity must be positive.',
                    ]);
                }

                $order->items()->create([
                    'tenant_id' => $order->tenant_id,
                    'variant_id' => $variantId,
                    'product_id' => $variant->product_id,
                    'qty' => $qty,
                    'unit_price' => $variant->price,
                    'cogs_amount' => $this->snapshotCogs($variant, $qty),
                ]);
            }

            $extras = $this->recalculateOrder($order);

            return $this->loadOrderRelations($order, $extras);
        });
    }

    /**
     * Replace the order items with the provided payload, validating selection rules and stock.
     *
     * @param array<int, array<string, mixed>> $lineItems
     */
    protected function syncLineItems(Order $order, array $lineItems): void
    {
        if ($order->status === 'paid') {
            throw ValidationException::withMessages([
                'order' => 'Cannot modify a paid order.',
            ]);
        }

        $lineItems = array_values($lineItems);

        if (count($lineItems) === 0) {
            $order->items()->delete();
            return;
        }

        $tenantId = (string) $order->tenant_id;

        $productIds = collect($lineItems)
            ->pluck('product_id')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            throw ValidationException::withMessages([
                'line_items' => ['At least one product is required.'],
            ]);
        }

        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $productIds->all())
            ->with([
                'variants',
                'optionGroups' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with([
                        'options' => fn ($optionsQuery) => $optionsQuery->where('is_active', true),
                    ]),
            ])
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'line_items' => ['One or more products are invalid.'],
            ]);
        }

        $storeId = $order->store_id ?? $this->stores->id();

        if (!$storeId) {
            throw ValidationException::withMessages([
                'store' => 'Store context is required to capture an order.',
            ]);
        }

        $preparedItems = [];
        $variantQuantities = [];

        foreach ($lineItems as $index => $payload) {
            $productId = (string) ($payload['product_id'] ?? '');
            $product = $products->get($productId);

            if (!$product) {
                throw ValidationException::withMessages([
                    "line_items.{$index}.product_id" => 'Product not found.',
                ]);
            }

            if (!(bool) $product->is_active) {
                throw ValidationException::withMessages([
                    "line_items.{$index}.product_id" => 'Product is inactive.',
                ]);
            }

            $qty = (float) ($payload['qty'] ?? 0);

            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    "line_items.{$index}.qty" => 'Quantity must be greater than zero.',
                ]);
            }

            $selectedOptionIds = collect($payload['selected_options'] ?? [])
                ->filter(fn ($value) => is_string($value) && $value !== '')
                ->unique()
                ->values()
                ->all();

            $note = isset($payload['note']) ? trim((string) $payload['note']) : null;
            if ($note === '') {
                $note = null;
            }

            $product->loadMissing(['variants', 'optionGroups.options']);
            $variant = $product->ensureDefaultVariant();

            $basePrice = (float) ($variant->price ?? $product->price ?? 0);

            $groups = $product->optionGroups
                ->filter(fn ($group) => (bool) $group->is_active)
                ->values();
            $optionsLookup = [];

            foreach ($groups as $group) {
                foreach ($group->options->filter(fn ($option) => (bool) $option->is_active) as $option) {
                    $optionsLookup[$option->id] = [$option, $group];
                }
            }

            $selectedOptions = [];

            foreach ($selectedOptionIds as $optionId) {
                if (!isset($optionsLookup[$optionId])) {
                    throw ValidationException::withMessages([
                        "line_items.{$index}.selected_options" => 'One or more selected modifiers are invalid.',
                    ]);
                }

                $selectedOptions[$optionId] = $optionsLookup[$optionId];
            }

            foreach ($groups as $group) {
                $groupSelections = array_filter(
                    $selectedOptions,
                    fn ($tuple) => $tuple[1]->id === $group->id
                );

                $count = count($groupSelections);
                $min = (int) ($group->min ?? 0);
                $max = $group->max !== null ? (int) $group->max : null;

                if ($group->selection_type === 'single' && $count > 1) {
                    throw ValidationException::withMessages([
                        "line_items.{$index}.selected_options" => "Only one option may be selected for {$group->name}.",
                    ]);
                }

                if ($count < $min) {
                    throw ValidationException::withMessages([
                        "line_items.{$index}.selected_options" => "Select at least {$min} option(s) for {$group->name}.",
                    ]);
                }

                if ($max !== null && $count > $max) {
                    throw ValidationException::withMessages([
                        "line_items.{$index}.selected_options" => "Select no more than {$max} option(s) for {$group->name}.",
                    ]);
                }
            }

            $preparedItems[] = [
                'variant' => $variant,
                'qty' => $qty,
                'base_price' => $basePrice,
                'note' => $note,
                'options' => array_map(fn ($tuple) => $tuple[0], array_values($selectedOptions)),
            ];

            if ((bool) $product->track_stock && (bool) $variant->track_stock) {
                $variantQuantities[$variant->id] = ($variantQuantities[$variant->id] ?? 0) + $qty;
            }
        }

        foreach ($variantQuantities as $variantId => $qtyNeeded) {
            $available = $this->stocks->getOnHand($variantId, $storeId);

            if ($qtyNeeded > $available) {
                throw ValidationException::withMessages([
                    'line_items' => ['Insufficient stock for one or more items.'],
                ]);
            }
        }

        $order->items()->delete();

        foreach ($preparedItems as $prepared) {
            $item = $order->items()->create([
                'tenant_id' => $order->tenant_id,
                'variant_id' => $prepared['variant']->id,
                'product_id' => $prepared['variant']->product_id,
                'qty' => $prepared['qty'],
                'unit_price' => $prepared['base_price'],
                'note' => $prepared['note'],
                'cogs_amount' => $this->snapshotCogs($prepared['variant'], $prepared['qty']),
            ]);

            foreach ($prepared['options'] as $option) {
                $item->options()->create([
                    'tenant_id' => $order->tenant_id,
                    'option_id' => $option->id,
                    'price_delta' => (float) ($option->price_delta ?? 0),
                ]);
            }
        }
    }

    public function calculateTotals(string $orderId): Order
    {
        return $this->db->transaction(function () use ($orderId) {
            $order = $this->getOrderForTenant($orderId, lock: true);
            $extras = $this->recalculateOrder($order);

            return $this->loadOrderRelations($order, $extras);
        });
    }

    public function checkout(string $orderId): Order
    {
        return $this->calculateTotals($orderId);
    }

    public function capture(string $orderId, string $method = 'cash', ?array $lineItems = null): Order
    {
        return $this->db->transaction(function () use ($orderId, $method, $lineItems) {
            $order = $this->getOrderForTenant($orderId, lock: true);

            if ($lineItems !== null) {
                $this->syncLineItems($order, $lineItems);
            }

            $extras = $this->recalculateOrder($order);

            if ($order->status === 'refunded') {
                throw ValidationException::withMessages([
                    'order' => 'Cannot capture a refunded order.',
                ]);
            }

            if ($order->status === 'paid') {
                return $this->loadOrderRelations($order, $extras);
            }

            $this->createPayment($order, $method);
            $this->applyInventoryDelta($order, -1, 'sale');

            $order->status = 'paid';
            $order->payment_method = $method;
            $order->save();

            $pointsMeta = $this->incrementCustomerPoints($order);

            return $this->loadOrderRelations($order, array_merge($extras, $pointsMeta));
        });
    }

    public function refund(string $orderId): Order
    {
        return $this->db->transaction(function () use ($orderId) {
            $order = $this->getOrderForTenant($orderId, lock: true);

            if ($order->status !== 'paid') {
                throw ValidationException::withMessages([
                    'order' => 'Only paid orders can be refunded from this action.',
                ]);
            }

            $amount = (float) $order->total;

            Refund::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'amount' => $amount,
                'reason' => 'Full order refund',
                'data' => ['mode' => 'full'],
            ]);

            $order->refunded_total = $amount;
            $order->status = 'refunded';
            $order->save();

            $this->applyInventoryDelta($order, +1, 'refund');

            return $this->loadOrderRelations($order);
        });
    }

    

    public function find(string $orderId): Order
    {
        return $this->calculateTotals($orderId);
    }

    public function setCustomer(string $orderId, string $customerId): Order
    {
        return $this->db->transaction(function () use ($orderId, $customerId) {
            $order = $this->getOrderForTenant($orderId, lock: true);

            $customer = Customer::query()
                ->where('tenant_id', $order->tenant_id)
                ->findOrFail($customerId);

            $order->customers()->sync([$customer->id]);

            $extras = $this->recalculateOrder($order);

            return $this->loadOrderRelations($order, $extras);
        });
    }

    public function applyDiscount(string $orderId, float $amount): Order
    {
        if ($amount < 0) {
            throw ValidationException::withMessages([
                'amount' => 'Discount must be zero or positive.',
            ]);
        }

        return $this->db->transaction(function () use ($orderId, $amount) {
            $order = $this->getOrderForTenant($orderId, lock: true);
            $order->manual_discount = $amount;
            $order->save();

            $extras = $this->recalculateOrder($order);

            return $this->loadOrderRelations($order, $extras);
        });
    }

    protected function createPayment(Order $order, string $method): Payment
    {
        return $order->payments()->create([
            'tenant_id' => $order->tenant_id,
            'method' => $method,
            'amount' => $order->total,
            'status' => 'captured',
            'captured_at' => Carbon::now(),
        ]);
    }

    protected function applyInventoryDelta(Order $order, int $direction, string $reason): void
    {
        $order->loadMissing('items');

        if (!$order->store_id) {
            return;
        }

        foreach ($order->items as $item) {
            $delta = $item->qty * $direction;

            if ($delta === 0.0) {
                continue;
            }

            $this->stocks->adjust(
                variantId: $item->variant_id,
                storeId: $order->store_id,
                delta: $delta,
                reason: $reason,
                reference: [
                    'type' => 'order',
                    'id' => $order->id,
                ],
                userId: $order->cashier_id,
                note: sprintf('Order %s %s', $order->id, $reason)
            );
        }
    }

    protected function getOrderForTenant(string $orderId, bool $lock = false): Order
    {
        $tenantId = auth()->user()->tenant_id;

        $query = Order::query()->where('tenant_id', $tenantId);
        $storeId = $this->stores->id();

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($orderId);
    }

    protected function loadOrderRelations(Order $order, array $extras = []): Order
    {
        $loaded = $order->fresh([
            'items.variant:id,sku,name,price,product_id',
            'items.variant.product:id,title',
            'items.variant.product.categories:id,name',
            'items.variant.product.taxes:id,name,rate,inclusive,is_active',
            'items.options.option:id,name,option_group_id,price_delta',
            'items.options.option.group:id,name',
            'payments',
            'cashier:id,name,email',
            'shift:id,register_id,opened_at,closed_at',
            'shift.register:id,name',
            'customers:id,name,loyalty_points,email,phone',
            'store:id,name,code',
        ]);

        foreach ($extras as $key => $value) {
            $loaded->setAttribute($key, $value);
        }

        return $loaded;
    }

    protected function incrementCustomerPoints(Order $order): array
    {
        $customer = $order->customers()->first();
        if (!$customer) {
            return [
                'points_earned' => 0,
                'loyalty_points_total' => null,
            ];
        }

        $pointsEarned = (int) floor((float) $order->total);
        if ($pointsEarned > 0) {
            $customer->increment('loyalty_points', $pointsEarned);
        }

        CustomerOrder::query()->updateOrCreate(
            ['order_id' => $order->id],
            ['customer_id' => $customer->id]
        );

        $customer->refresh();

        return [
            'points_earned' => $pointsEarned,
            'loyalty_points_total' => $customer->loyalty_points,
        ];
    }

    protected function recalculateOrder(Order $order): array
    {
        $order->loadMissing([
            'items.variant.product.categories',
            'items.variant.product.taxes',
            'items.options',
        ]);

        $subtotal = 0.0;
        $promotionDiscountTotal = 0.0;
        $manualDiscount = max((float) $order->manual_discount, 0);
        $exclusiveTaxTotal = 0.0;
        $inclusiveTaxTotal = 0.0;
        $promotionNames = [];
        $taxBreakdown = [];

        $promotions = $this->activePromotions($order->tenant_id);

        foreach ($order->items as $item) {
            $optionUnitTotal = $item->options->sum(fn ($option) => (float) $option->price_delta);
            $lineSubtotal = (float) $item->qty * ((float) $item->unit_price + $optionUnitTotal);
            $lineSubtotal = max($lineSubtotal, 0);
            $subtotal += $lineSubtotal;

            $product = $item->variant->product;
            $categories = $product->categories->pluck('id')->all();

            $bestPromotion = $this->determineBestPromotion(
                $promotions,
                $product->id,
                $categories,
                $lineSubtotal,
                (float) $item->qty
            );

            $linePromotionDiscount = $bestPromotion['amount'] ?? 0;
            if (!empty($bestPromotion['name'])) {
                $promotionNames[] = $bestPromotion['name'];
            }

            $promotionDiscountTotal += $linePromotionDiscount;
            $discountedBase = max($lineSubtotal - $linePromotionDiscount, 0);

            foreach ($product->taxes as $tax) {
                if (!$tax->is_active) {
                    continue;
                }

                $rate = max((float) $tax->rate, 0);
                if ($rate <= 0) {
                    continue;
                }

                $taxAmount = 0.0;
                if ($tax->inclusive) {
                    $taxAmount = $discountedBase * ($rate / (100 + $rate));
                    $inclusiveTaxTotal += $taxAmount;
                } else {
                    $taxAmount = $discountedBase * ($rate / 100);
                    $exclusiveTaxTotal += $taxAmount;
                }

                $key = (string) $tax->id;
                if (!isset($taxBreakdown[$key])) {
                    $taxBreakdown[$key] = [
                        'id' => $tax->id,
                        'name' => $tax->name,
                        'inclusive' => (bool) $tax->inclusive,
                        'amount' => 0.0,
                    ];
                }

                $taxBreakdown[$key]['amount'] += $taxAmount;
            }
        }

        $subtotal = round($subtotal, 2);
        $promotionDiscountTotal = round($promotionDiscountTotal, 2);
        $totalDiscount = round($manualDiscount + $promotionDiscountTotal, 2);

        if ($totalDiscount > $subtotal) {
            $totalDiscount = $subtotal;
        }

        $exclusiveTaxTotal = round($exclusiveTaxTotal, 2);
        $inclusiveTaxTotal = round($inclusiveTaxTotal, 2);
        $taxTotal = round($exclusiveTaxTotal + $inclusiveTaxTotal, 2);

        $total = round(max($subtotal - $totalDiscount + $exclusiveTaxTotal, 0), 2);

        $order->subtotal = $subtotal;
        $order->discount = $totalDiscount;
        $order->manual_discount = round($manualDiscount, 2);
        $order->tax = $taxTotal;
        $order->total = $total;
        $order->save();

        $formattedTaxBreakdown = array_map(function (array $entry) {
            $entry['amount'] = round($entry['amount'], 2);
            return $entry;
        }, array_values($taxBreakdown));

        return [
            'tax_breakdown' => $formattedTaxBreakdown,
            'promotion_breakdown' => array_values(array_unique($promotionNames)),
            'promotion_discount' => $promotionDiscountTotal,
            'exclusive_tax_total' => $exclusiveTaxTotal,
            'inclusive_tax_total' => $inclusiveTaxTotal,
        ];
    }

    protected function activePromotions(string $tenantId): Collection
    {
        $now = Carbon::now();

        return Promotion::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->get();
    }

    protected function determineBestPromotion(
        Collection $promotions,
        string $productId,
        array $categoryIds,
        float $lineSubtotal,
        float $quantity
    ): array {
        $best = ['amount' => 0.0, 'name' => null];

        foreach ($promotions as $promotion) {
            if (!$this->promotionApplies($promotion, $productId, $categoryIds)) {
                continue;
            }

            $value = max((float) $promotion->value, 0);
            $amount = 0.0;

            if ($promotion->type === 'percent') {
                $percent = min($value, 100);
                $amount = $lineSubtotal * ($percent / 100);
            } else {
                $amount = min($lineSubtotal, $value * max($quantity, 1));
            }

            if ($amount > $best['amount']) {
                $best = [
                    'amount' => $amount,
                    'name' => $promotion->name,
                ];
            }
        }

        return $best;
    }

    protected function promotionApplies(Promotion $promotion, string $productId, array $categoryIds): bool
    {
        return match ($promotion->applies_to) {
            'all' => true,
            'product' => $promotion->product_id === $productId,
            'category' => $promotion->category_id && in_array($promotion->category_id, $categoryIds, true),
            default => false,
        };
    }

    protected function snapshotCogs(Variant $variant, float $quantity): float
    {
        $unitCost = max((float) ($variant->cost ?? 0), 0);
        $qty = max($quantity, 0);

        return round($unitCost * $qty, 2);
    }
}
