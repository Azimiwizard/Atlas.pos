<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderRefundController extends Controller
{
    public function refund(Request $request, string $id)
    {
        $order = Order::with(['items'])->findOrFail($id);

        $validated = $request->validate([
            'item_ids' => 'required|array',
            'item_ids.*' => 'integer|min:1',
            'reason' => 'nullable|string',
            'refund_amount' => 'nullable|numeric|min:0',
        ]);

        $itemsMap = $validated['item_ids'];

        // Build sold qty map and refunded per item if tracked
        $soldMap = [];
        foreach ($order->items as $item) {
            $soldMap[$item->variant_id] = ($soldMap[$item->variant_id] ?? 0) + $item->quantity;
        }

        $refundingAmount = 0.0;
        $refundedLines = [];

        foreach ($itemsMap as $variantId => $qtyToRefund) {
            $variantId = (int)$variantId;
            $qtyToRefund = (int)$qtyToRefund;
            if ($qtyToRefund <= 0) continue;
            $soldQty = $soldMap[$variantId] ?? 0;
            // Basic validation: cannot refund more than sold
            if ($qtyToRefund > $soldQty) {
                return response()->json([
                    'message' => 'Refund quantity exceeds sold quantity for variant ' . $variantId
                ], 422);
            }

            // compute unit price from order items (average if multiple lines)
            $lines = $order->items->where('variant_id', $variantId);
            $totalLineAmount = 0; $totalLineQty = 0; $name = null;
            foreach ($lines as $line) {
                $totalLineAmount += ($line->unit_price * $line->quantity) - ($line->discount ?? 0);
                $totalLineQty += $line->quantity;
                $name = $name ?? $line->name ?? null;
            }
            $unit = $totalLineQty > 0 ? $totalLineAmount / $totalLineQty : 0;
            $lineRefund = round($unit * $qtyToRefund, 2);
            $refundingAmount += $lineRefund;
            $refundedLines[] = [
                'variant_id' => $variantId,
                'name' => $name,
                'qty' => $qtyToRefund,
                'unit' => round($unit, 2),
                'amount' => $lineRefund,
            ];
        }

        if (isset($validated['refund_amount'])) {
            $refundingAmount = (float)$validated['refund_amount'];
        }

        if ($refundingAmount <= 0) {
            return response()->json(['message' => 'Refund amount must be greater than 0'], 422);
        }

        return DB::transaction(function () use ($order, $refundingAmount, $validated, $refundedLines, $request) {
            $refund = Refund::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'user_id' => $request->user()->id,
                'amount' => $refundingAmount,
                'reason' => $validated['reason'] ?? null,
                'data' => [
                    'lines' => $refundedLines,
                ],
            ]);

            $order->refunded_total = round(((float)$order->refunded_total) + $refundingAmount, 2);
            $order->save();

            return response()->json([
                'refund' => $refund,
                'order' => $order->fresh(),
            ]);
        });
    }

    public function receipt(string $id)
    {
        $order = Order::with(['items', 'user', 'refunds'])->findOrFail($id);
        $data = [
            'order' => $order,
            'totals' => [
                'subtotal' => (float)$order->subtotal,
                'tax' => (float)$order->tax,
                'discount' => (float)$order->discount,
                'total' => (float)$order->total,
                'total_refunded' => (float)$order->refunded_total,
                'net_total' => round(((float)$order->total) - ((float)$order->refunded_total), 2),
            ],
            'refunds' => $order->refunds,
        ];
        return response()->json($data);
    }
}
