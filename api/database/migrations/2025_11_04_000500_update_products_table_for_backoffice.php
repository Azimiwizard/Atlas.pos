<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            // Add new fields only if they don’t exist already
            if (! Schema::hasColumn('products', 'description')) {
                $table->text('description')->nullable();
            }

            if (! Schema::hasColumn('products', 'menu_category_id')) {
                $table->uuid('menu_category_id')->nullable()->after('tenant_id');
            }

            if (! Schema::hasColumn('products', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }

            if (! Schema::hasColumn('products', 'sort_order')) {
                $table->integer('sort_order')->default(0);
            }
        });

        // Add the foreign key only if menu_categories exists
        if (Schema::hasTable('menu_categories')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'menu_category_id')) {
                    $table->foreign('menu_category_id')
                          ->references('id')
                          ->on('menu_categories')
                          ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'menu_category_id')) {
                $table->dropForeign(['menu_category_id']);
                $table->dropColumn('menu_category_id');
            }

            if (Schema::hasColumn('products', 'description')) {
                $table->dropColumn('description');
            }

            if (Schema::hasColumn('products', 'is_active')) {
                $table->dropColumn('is_active');
            }

            if (Schema::hasColumn('products', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
