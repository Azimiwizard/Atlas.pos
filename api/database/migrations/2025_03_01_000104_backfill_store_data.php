<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tenants')
            ->orderBy('id')
            ->chunk(50, function ($tenants) use ($now) {
                foreach ($tenants as $tenant) {
                    DB::transaction(function () use ($tenant, $now) {
                        $store = DB::table('stores')
                            ->where('tenant_id', $tenant->id)
                            ->orderBy('created_at')
                            ->first();

                        if (!$store) {
                            $storeId = (string) Str::uuid();

                            DB::table('stores')->insert([
                                'id' => $storeId,
                                'tenant_id' => $tenant->id,
                                'name' => 'Main Store',
                                'code' => 'MAIN',
                                'address' => null,
                                'phone' => null,
                                'is_active' => true,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        } else {
                            $storeId = $store->id;
                        }

                        DB::table('registers')
                            ->where('tenant_id', $tenant->id)
                            ->whereNull('store_id')
                            ->update([
                                'store_id' => $storeId,
                                'updated_at' => $now,
                            ]);

                        $registerStore = DB::table('registers')
                            ->where('tenant_id', $tenant->id)
                            ->pluck('store_id', 'id');

                        DB::table('shifts')
                            ->where('tenant_id', $tenant->id)
                            ->whereNull('store_id')
                            ->orderBy('id')
                            ->chunkById(100, function ($shifts) use ($registerStore, $storeId, $now) {
                                foreach ($shifts as $shift) {
                                    $storeForShift = $registerStore[$shift->register_id] ?? $storeId;

                                    DB::table('shifts')
                                        ->where('id', $shift->id)
                                        ->update([
                                            'store_id' => $storeForShift,
                                            'updated_at' => $now,
                                        ]);
                                }
                            }, 'id');

                        DB::table('users')
                            ->where('tenant_id', $tenant->id)
                            ->whereNull('store_id')
                            ->where('role', 'cashier')
                            ->update([
                                'store_id' => $storeId,
                                'updated_at' => $now,
                            ]);

                        $shiftStore = DB::table('shifts')
                            ->where('tenant_id', $tenant->id)
                            ->pluck('store_id', 'id');

                        DB::table('orders')
                            ->where('tenant_id', $tenant->id)
                            ->whereNull('store_id')
                            ->orderBy('id')
                            ->chunkById(100, function ($orders) use ($shiftStore, $storeId, $now) {
                                foreach ($orders as $order) {
                                    $storeForOrder = $order->shift_id ? ($shiftStore[$order->shift_id] ?? $storeId) : $storeId;

                                    DB::table('orders')
                                        ->where('id', $order->id)
                                        ->update([
                                            'store_id' => $storeForOrder,
                                            'updated_at' => $now,
                                        ]);
                                }
                            }, 'id');

                        DB::table('stock_levels')
                            ->where('tenant_id', $tenant->id)
                            ->whereNull('store_id')
                            ->update([
                                'store_id' => $storeId,
                                'updated_at' => $now,
                            ]);

                        DB::table('stock_levels')
                            ->whereNull('tenant_id')
                            ->update([
                                'tenant_id' => $tenant->id,
                                'updated_at' => $now,
                            ]);

                        DB::table('inventory_ledger')
                            ->where('tenant_id', $tenant->id)
                            ->whereNull('store_id')
                            ->update([
                                'store_id' => $storeId,
                                'updated_at' => $now,
                            ]);

                        DB::table('inventory_ledger')
                            ->whereNull('tenant_id')
                            ->update([
                                'tenant_id' => $tenant->id,
                                'updated_at' => $now,
                            ]);
                    });
                }
            });
    }

    public function down(): void
    {
        // No rollback for data backfill.
    }
};
