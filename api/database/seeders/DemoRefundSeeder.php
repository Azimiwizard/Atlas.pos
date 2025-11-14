<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;

class DemoRefundSeeder extends Seeder
{
    public function run(): void
    {
        $order = Order::query()->with('items')->latest()->first();
        $user = User::query()->first();
        if (!$order || !$user) return;

        if ((float) ($order->refunded_total ?? 0) <= 0 && (float) $order->total > 0) {
            Refund::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'amount' => min(5.00, (float) $order->total),
                'reason' => 'Demo partial refund',
                'data' => [
                    'lines' => [],
                ],
            ]);
            $order->refunded_total = min(5.00, (float) $order->total);
            $order->save();
        }
    }
}
