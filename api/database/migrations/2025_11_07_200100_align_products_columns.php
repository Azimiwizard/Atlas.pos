<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected string $skuIndex = 'products_tenant_sku_unique';

    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'sku')) {
                $table->string('sku', 64)->nullable()->after('title');
            }
            if (! Schema::hasColumn('products', 'price')) {
                $table->decimal('price', 12, 2)->default(0)->after('barcode');
            }
            if (! Schema::hasColumn('products', 'tax_code')) {
                $table->string('tax_code', 64)->nullable()->after('price');
            }
            if (! Schema::hasColumn('products', 'track_stock')) {
                $table->boolean('track_stock')->default(true)->after('tax_code');
            }
            if (! Schema::hasColumn('products', 'image_url')) {
                $table->string('image_url', 2048)->nullable()->after('track_stock');
            }
        });

        // Add unique(tenant_id, sku) if not already present.
        try {
            Schema::table('products', function (Blueprint $table) {
                $table->unique(['tenant_id', 'sku'], $this->skuIndex);
            });
        } catch (\Throwable $e) {
            // ignore if index already exists / concurrent creation
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            // Best-effort drop of the unique index
            try {
                $table->dropUnique($this->skuIndex);
            } catch (\Throwable $e) {
                // ignore if it doesn't exist
            }

            if (Schema::hasColumn('products', 'image_url')) {
                $table->dropColumn('image_url');
            }
            if (Schema::hasColumn('products', 'track_stock')) {
                $table->dropColumn('track_stock');
            }
            if (Schema::hasColumn('products', 'tax_code')) {
                $table->dropColumn('tax_code');
            }
            if (Schema::hasColumn('products', 'price')) {
                $table->dropColumn('price');
            }
            if (Schema::hasColumn('products', 'sku')) {
                $table->dropColumn('sku');
            }
        });
    }
};
