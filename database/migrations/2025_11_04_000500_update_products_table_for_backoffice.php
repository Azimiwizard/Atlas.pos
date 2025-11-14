<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'barcode')) {
                try {
                    $table->dropUnique('products_barcode_unique');
                } catch (\Throwable $exception) {
                    // Ignore if the legacy unique already removed.
                }
            }

            if (!Schema::hasColumn('products', 'sku')) {
                $table->string('sku', 80)->nullable();
            }

            if (!Schema::hasColumn('products', 'category_id')) {
                $table->foreignUuid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            }

            if (!Schema::hasColumn('products', 'price')) {
                $table->decimal('price', 12, 2)->default(0);
            }

            if (!Schema::hasColumn('products', 'tax_code')) {
                $table->string('tax_code', 32)->nullable();
            }

            if (!Schema::hasColumn('products', 'track_stock')) {
                $table->boolean('track_stock')->default(true);
            }

            if (!Schema::hasColumn('products', 'image_url')) {
                $table->string('image_url', 1024)->nullable();
            }

            if (!Schema::hasColumn('products', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }

            if (!Schema::hasColumn('products', 'created_at')) {
                $table->timestamps();
            }
        });

        if (Schema::hasColumn('products', 'title')) {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE products ALTER COLUMN title TYPE VARCHAR(160)');
            } else {
                DB::statement('ALTER TABLE `products` MODIFY `title` VARCHAR(160)');
            }
        }

        if (Schema::hasColumn('products', 'barcode')) {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE products ALTER COLUMN barcode TYPE VARCHAR(255)');
            } else {
                DB::statement('ALTER TABLE `products` MODIFY `barcode` VARCHAR(255) NULL');
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['tenant_id', 'barcode'], 'products_tenant_barcode_unique');
            $table->index('created_at', 'products_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            try {
                $table->dropUnique('products_tenant_barcode_unique');
            } catch (\Throwable $exception) {
                // Ignore if the scoped unique does not exist.
            }

            try {
                $table->dropIndex('products_created_at_index');
            } catch (\Throwable $exception) {
                // Ignore if the index was never added.
            }

            if (Schema::hasColumn('products', 'category_id')) {
                $table->dropConstrainedForeignId('category_id');
            }

            $columnsToDrop = [
                'sku',
                'price',
                'tax_code',
                'track_stock',
                'image_url',
            ];

            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('products', 'title')) {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE products ALTER COLUMN title TYPE VARCHAR(255)');
            } else {
                DB::statement('ALTER TABLE `products` MODIFY `title` VARCHAR(255)');
            }
        }

        if (Schema::hasColumn('products', 'barcode')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique('barcode', 'products_barcode_unique');
            });
        }
    }
};
