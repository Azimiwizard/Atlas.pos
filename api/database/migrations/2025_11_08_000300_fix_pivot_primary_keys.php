<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->fixPivotTable(
            'product_category',
            ['product_id', 'category_id'],
            fn (Blueprint $table) => $table
                ->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnDelete(),
            fn (Blueprint $table) => $table
                ->foreignUuid('category_id')
                ->constrained('categories')
                ->cascadeOnDelete()
        );

        $this->fixPivotTable(
            'product_tax',
            ['product_id', 'tax_id'],
            fn (Blueprint $table) => $table
                ->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnDelete(),
            fn (Blueprint $table) => $table
                ->foreignUuid('tax_id')
                ->constrained('taxes')
                ->cascadeOnDelete()
        );
    }

    public function down(): void
    {
        $this->restoreIdPrimary('product_category');
        $this->restoreIdPrimary('product_tax');
    }

    protected function fixPivotTable(
        string $tableName,
        array $primaryColumns,
        callable $firstColumn,
        callable $secondColumn
    ): void {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $this->dropPrimaryIfExists($table, $tableName);

            if (Schema::hasColumn($tableName, 'id')) {
                $table->dropColumn('id');
            }
        });

        Schema::table($tableName, function (Blueprint $table) use ($firstColumn, $secondColumn, $primaryColumns) {
            foreach ($primaryColumns as $column) {
                if (!Schema::hasColumn($table->getTable(), $column)) {
                    // Recreate the foreign UUID columns if missing.
                    if ($column === $primaryColumns[0]) {
                        $firstColumn($table);
                    } else {
                        $secondColumn($table);
                    }
                }
            }

            $table->primary($primaryColumns, $this->primaryName($table->getTable(), $primaryColumns));
        });
    }

    protected function restoreIdPrimary(string $tableName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $this->dropPrimaryIfExists($table, $tableName);

            if (!Schema::hasColumn($tableName, 'id')) {
                $table->uuid('id')->first();
            }

            $table->primary('id');
        });
    }

    protected function dropPrimaryIfExists(Blueprint $table, string $tableName): void
    {
        $connection = Schema::getConnection();
        $schemaManager = $connection->getDoctrineSchemaManager();
        $tableWithPrefix = $connection->getTablePrefix() . $tableName;
        $indexes = $schemaManager->listTableIndexes($tableWithPrefix);

        foreach ($indexes as $name => $index) {
            if ($index->isPrimary()) {
                $table->dropPrimary($name);
            }
        }
    }

    protected function primaryName(string $table, array $columns): string
    {
        return Str::slug($table . '_' . implode('_', $columns) . '_primary', '_');
    }
};
