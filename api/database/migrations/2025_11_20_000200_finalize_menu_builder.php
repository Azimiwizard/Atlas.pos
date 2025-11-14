<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('image_url', 1024)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tenant_id', 'menu_categories_tenant_index');
            $table->index(['tenant_id', 'sort_order'], 'menu_categories_tenant_sort_index');
            $table->index(['tenant_id', 'is_active'], 'menu_categories_tenant_active_index');
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'menu_category_id')) {
                $table->foreignUuid('menu_category_id')->nullable()->after('tenant_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('menu_category_id')
                ->references('id')
                ->on('menu_categories')
                ->nullOnDelete();

            $table->index(['tenant_id', 'menu_category_id'], 'products_tenant_menu_category_index');
        });

        Schema::create('option_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 160);
            $table->enum('selection_type', ['single', 'multiple'])->default('single');
            $table->unsignedInteger('min')->default(0);
            $table->unsignedInteger('max')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tenant_id', 'option_groups_tenant_index');
            $table->index(['tenant_id', 'product_id'], 'option_groups_tenant_product_index');
        });

        Schema::create('options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('option_group_id')->constrained('option_groups')->cascadeOnDelete();
            $table->string('name', 160);
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tenant_id', 'options_tenant_index');
            $table->index(['tenant_id', 'option_group_id'], 'options_tenant_group_index');
        });

        Schema::create('line_item_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignUuid('option_id')->constrained('options')->cascadeOnDelete();
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->timestamps();

            $table->index('tenant_id', 'line_item_options_tenant_index');
            $table->index(['tenant_id', 'order_item_id'], 'line_item_options_tenant_order_item_index');
        });
    }

    public function down(): void
{
    // Drop dependent tables first to avoid FK conflicts
    if (Schema::hasTable('line_item_options')) {
        Schema::dropIfExists('line_item_options');
    }

    if (Schema::hasTable('options')) {
        Schema::dropIfExists('options');
    }

    if (Schema::hasTable('option_groups')) {
        Schema::dropIfExists('option_groups');
    }

    // Safely remove the foreign key + column from products if present
    if (Schema::hasTable('products') && Schema::hasColumn('products', 'menu_category_id')) {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'menu_category_id')) {
                $table->dropForeign(['menu_category_id']);
                // dropIndex only if it exists (try/catch avoids crashes)
                try {
                    $table->dropIndex('products_tenant_menu_category_index');
                } catch (\Exception $e) {
                    // ignore if index missing
                }
                $table->dropColumn('menu_category_id');
            }
        });
    }

    if (Schema::hasTable('menu_categories')) {
        Schema::dropIfExists('menu_categories');
    }
}
};
