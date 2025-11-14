<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'subtotal')) {
                $table->decimal('subtotal', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('orders', 'tax')) {
                $table->decimal('tax', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('orders', 'discount')) {
                $table->decimal('discount', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('orders', 'total')) {
                $table->decimal('total', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method')->nullable();
            }
            if (!Schema::hasColumn('orders', 'refunded_total')) {
                $table->decimal('refunded_total', 12, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'subtotal')) $table->dropColumn('subtotal');
            if (Schema::hasColumn('orders', 'tax')) $table->dropColumn('tax');
            if (Schema::hasColumn('orders', 'discount')) $table->dropColumn('discount');
            if (Schema::hasColumn('orders', 'total')) $table->dropColumn('total');
            if (Schema::hasColumn('orders', 'payment_method')) $table->dropColumn('payment_method');
            if (Schema::hasColumn('orders', 'refunded_total')) $table->dropColumn('refunded_total');
        });
    }
};

