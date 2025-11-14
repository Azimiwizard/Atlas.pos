<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->string('barcode')->nullable()->after('sku');
            $table->boolean('is_default')->default(false)->after('track_stock');
            $table->index(['product_id', 'is_default'], 'variants_product_default_index');
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropIndex('variants_product_default_index');
            $table->dropColumn(['barcode', 'is_default']);
        });
    }
};
