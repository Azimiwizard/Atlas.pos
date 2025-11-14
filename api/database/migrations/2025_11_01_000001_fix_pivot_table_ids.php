<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If the legacy pivot table doesn't exist anymore, do nothing.
        if (! Schema::hasTable('product_category')) {
            return;
        }

        // Drop the auto-increment/uuid id column if present (legacy cleanup)
        Schema::table('product_category', function (Blueprint $table) {
            if (Schema::hasColumn('product_category', 'id')) {
                $table->dropColumn('id');
            }
        });

        // Ensure a composite uniqueness (or primary key) on product_id + category_id
        // Using UNIQUE is safer in Postgres when prior PK names are unknown.
        try {
            DB::statement('
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_indexes
                        WHERE schemaname = ANY (current_schemas(false))
                          AND indexname = "product_category_product_id_category_id_unique"
                    ) THEN
                        CREATE UNIQUE INDEX product_category_product_id_category_id_unique
                        ON product_category (product_id, category_id);
                    END IF;
                END$$;
            ');
        } catch (\Throwable $e) {
            // Ignore if index already exists / any mismatch; this is legacy hardening.
        }
    }

    public function down(): void
    {
        // If table no longer exists, nothing to rollback.
        if (! Schema::hasTable('product_category')) {
            return;
        }

        // Best-effort rollback: drop the unique index if present.
        try {
            DB::statement('DROP INDEX IF EXISTS product_category_product_id_category_id_unique;');
        } catch (\Throwable $e) {
            // ignore
        }

        // We don't recreate an `id` column because the legacy schema was incorrect;
        // leaving it without `id` is fine for a pivot.
    }
};
