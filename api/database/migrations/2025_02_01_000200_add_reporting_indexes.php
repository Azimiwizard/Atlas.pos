<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'orders_tenant_created_idx');
            $table->index(['tenant_id', 'status'], 'orders_tenant_status_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id', 'order_items_order_idx');
            $table->index('variant_id', 'order_items_variant_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('order_id', 'payments_order_idx');
            $table->index('method', 'payments_method_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_tenant_created_idx');
            $table->dropIndex('orders_tenant_status_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_idx');
            $table->dropIndex('order_items_variant_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_order_idx');
            $table->dropIndex('payments_method_idx');
        });
    }
};

