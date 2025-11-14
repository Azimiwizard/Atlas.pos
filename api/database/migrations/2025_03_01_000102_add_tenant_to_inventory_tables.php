<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add nullable tenant_id columns first
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
        });

        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
        });

        // Backfill tenant_id using products (Postgres-compatible UPDATE ... FROM)
        DB::statement("
            UPDATE stock_levels sl
            SET tenant_id = p.tenant_id
            FROM variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id = sl.variant_id
        ");

        DB::statement("
            UPDATE inventory_ledger il
            SET tenant_id = p.tenant_id
            FROM variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id = il.variant_id
        ");

        // Enforce NOT NULL and add FKs (Postgres-safe)
        DB::statement('ALTER TABLE stock_levels ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement('ALTER TABLE inventory_ledger ALTER COLUMN tenant_id SET NOT NULL');

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Drop FKs then columns
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
