<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 160);
            // e.g. 'percent' or 'amount' — keep string to stay DB-agnostic
            $table->string('type', 20); 
            $table->decimal('value', 12, 2);
            // use menu_category_id going forward; nullable and FK added later if table exists
            $table->uuid('menu_category_id')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // indexes / FKs that are always safe now
            $table->index(['tenant_id', 'is_active']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Add FK to menu_categories only if it already exists (guards ordering)
        if (Schema::hasTable('menu_categories')) {
            Schema::table('promotions', function (Blueprint $table) {
                // guard column too in case of future edits
                if (Schema::hasColumn('promotions', 'menu_category_id')) {
                    $table->foreign('menu_category_id')
                          ->references('id')->on('menu_categories')
                          ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('promotions')) {
            Schema::dropIfExists('promotions');
        }
    }
};
