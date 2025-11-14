<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\ShiftService;
use App\Services\StoreManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected ShiftService $shifts,
        protected StoreManager $stores
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'paid', 'refunded'])],
            'date' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'store_id' => ['nullable', 'string'],
        ]);

        $tenantId = (string) $request->user()->tenant_id;
        $status = $request->query('status');
        $dateFilter = $request->query('date');
        $perPage = (int) $request->query('per_page', 15);
        $storeFilter = $request->query('store_id') ?: $this->stores->id();

        $query = Order::query()->where('tenant_id', $tenantId);

        if ($request->user()->role === 'cashier') {
            $storeFilter = $request->user()->store_id;
        }

        if ($storeFilter === 'all' && $request->user()->role !== 'cashier') {
            $storeFilter = null;
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($storeFilter) {
            $query->where('store_id', $storeFilter);
        }

        if (is_string($dateFilter) && $dateFilter !== '') {
            if ($dateFilter === 'today') {
                $query->whereDate('created_at', now()->toDateString());
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
                $query->whereDate('created_at', $dateFilter);
            } else {
                throw ValidationException::withMessages([
                    'date' => 'The date filter must be "today" or formatted as YYYY-MM-DD.',
                ]);
            }
        }

        $orders = $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($orders);
    }

    public function createDraft(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['nullable', 'string'],
        ]);

        $storeId = $request->input('store_id') ?: $this->stores->id();
        if ($storeId === 'all' && $request->user()->role !== 'cashier') {
            $storeId = null;
        }
        $order = $this->orders->createDraft($request->user(), $storeId);

        return response()->json($order, 201);
    }

    public function addItem(Request $request, string $orderId): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'string'],
            'qty' => ['required', 'numeric'],
        ]);

        $order = $this->orders->addItem($orderId, $data['variant_id'], (float) $data['qty']);

        return response()->json($order);
    }

    public function checkout(string $orderId): JsonResponse
    {
        $order = $this->orders->checkout($orderId);

        return response()->json($order);
    }

    public function capture(Request $request, string $orderId): JsonResponse
    {
        $data = $request->validate([
            'method' => ['nullable', 'in:cash,card'],
            'line_items' => ['nullable', 'array'],
            'line_items.*.product_id' => ['required_with:line_items', 'uuid'],
            'line_items.*.qty' => ['required_with:line_items', 'numeric', 'min:0.01'],
            'line_items.*.selected_options' => ['nullable', 'array'],
            'line_items.*.selected_options.*' => ['uuid'],
            'line_items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->orders->capture($orderId, $data['method'] ?? 'cash', $data['line_items'] ?? null);
        $order->loadMissing(['store', 'cashier', 'items', 'payments']);

        return response()->json($this->formatOrderSummary($order));
    }

    public function attachCustomer(Request $request, string $orderId): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'string'],
        ]);

        $order = $this->orders->setCustomer($orderId, $data['customer_id']);

        return response()->json($order);
    }

    public function applyDiscount(Request $request, string $orderId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $order = $this->orders->applyDiscount($orderId, (float) $data['amount']);

        return response()->json($order);
    }

    public function attachShift(Request $request, string $orderId): JsonResponse
    {
        $data = $request->validate([
            'shift_id' => ['required', 'string'],
        ]);

        $order = $this->shifts->attachOrder($orderId, $data['shift_id'], $request->user());

        return response()->json($order);
    }

    public function refund(string $orderId): JsonResponse
    {
        $order = $this->orders->refund($orderId);

        return response()->json($order);
    }

    public function show(string $orderId): JsonResponse
    {
        return response()->json($this->orders->find($orderId));
    }

    public function receipt(Request $request, string $orderId): JsonResponse
    {
        abort_unless(Str::isUuid($orderId), 404);

        $tenantId = (string) $request->user()->tenant_id;

        $order = Order::query()
            ->with([
                'items.variant.product',
                'items.options.option.group',
                'payments',
                'customers',
                'shift.register',
                'store',
                'cashier',
            ])
            ->where('tenant_id', $tenantId)
            ->where('id', $orderId)
            ->first();

        abort_if($order === null, 404);

        return response()->json($this->formatReceiptPayload($order));
    }

    protected function formatOrderSummary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number ?? null,
            'store_id' => $order->store_id,
            'cashier_id' => $order->cashier_id,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'tax' => (float) $order->tax,
            'discount' => (float) $order->discount,
            'manual_discount' => (float) $order->manual_discount,
            'total' => (float) $order->total,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
        ];
    }

    protected function formatReceiptPayload(Order $order): array
    {
        $store = $order->store;
        $cashier = $order->cashier;
        $customer = $order->customers->first();

        $items = $order->items->map(function ($item) {
            $variant = $item->variant;
            $product = $variant?->product;
            $optionUnitTotal = $item->options->sum(fn ($option) => (float) $option->price_delta);
            $options = $item->options->map(function ($lineOption) {
                $option = $lineOption->option;
                $group = $option?->group;

                return [
                    'id' => $lineOption->option_id,
                    'name' => $option?->name,
                    'group_name' => $group?->name,
                    'price_delta' => (float) $lineOption->price_delta,
                ];
            })->values()->all();

            return [
                'id' => $item->id,
                'variant_id' => $variant?->id,
                'title' => $product?->title ?? $variant?->name ?? 'Item',
                'sku' => $variant?->sku,
                'qty' => (float) $item->qty,
                'unit_price' => round((float) $item->unit_price + $optionUnitTotal, 2),
                'base_price' => (float) $item->unit_price,
                'options_total' => round($optionUnitTotal, 2),
                'line_total' => (float) $item->line_total,
                'note' => $item->note,
                'options' => $options,
            ];
        })->values()->all();

        $payments = $order->payments->map(function ($payment) {
            return [
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'status' => $payment->status,
                'captured_at' => optional($payment->captured_at)->toIso8601String(),
            ];
        })->values()->all();

        $refundedTotal = (float) ($order->refunded_total ?? 0);

        return [
            'id' => $order->id,
            'number' => $order->number ?? null,
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code ?? null,
            ] : null,
            'cashier' => $cashier ? [
                'id' => $cashier->id,
                'name' => $cashier->name,
            ] : null,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'items' => $items,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'tax' => (float) $order->tax,
            'total' => (float) $order->total,
            'payments' => $payments,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'loyalty_points' => $customer->loyalty_points,
            ] : null,
            'refunded_total' => $refundedTotal,
            'net_total' => round(((float) $order->total) - $refundedTotal, 2),
        ];
    }
}
