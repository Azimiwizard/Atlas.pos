<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropUnique('stock_levels_variant_id_store_id_unique');
        });

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->unique(['tenant_id', 'store_id', 'variant_id'], 'stock_levels_tenant_store_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropUnique('stock_levels_tenant_store_variant_unique');
        });

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->unique(['variant_id', 'store_id']);
        });
    }
};

