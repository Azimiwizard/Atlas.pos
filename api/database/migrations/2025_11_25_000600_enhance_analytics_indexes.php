<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addOrderItemDimensions();
        $this->addPaymentTenantColumn();
        $this->addRefundTenantColumn();
        $this->addSupportIndexes();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if ($this->indexExists('orders', 'orders_tenant_store_created_idx')) {
                $table->dropIndex('orders_tenant_store_created_idx');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if ($this->indexExists('order_items', 'order_items_tenant_product_idx')) {
                $table->dropIndex('order_items_tenant_product_idx');
            }
            if ($this->indexExists('order_items', 'order_items_tenant_order_idx')) {
                $table->dropIndex('order_items_tenant_order_idx');
            }
            if (Schema::hasColumn('order_items', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
            if (Schema::hasColumn('order_items', 'product_id')) {
                $table->dropForeign(['product_id']);
                $table->dropColumn('product_id');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if ($this->indexExists('payments', 'payments_tenant_method_created_idx')) {
                $table->dropIndex('payments_tenant_method_created_idx');
            }
            if (Schema::hasColumn('payments', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });

        Schema::table('refunds', function (Blueprint $table) {
            if ($this->indexExists('refunds', 'refunds_tenant_created_idx')) {
                $table->dropIndex('refunds_tenant_created_idx');
            }
            if (Schema::hasColumn('refunds', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if ($this->indexExists('products', 'products_tenant_category_idx')) {
                $table->dropIndex('products_tenant_category_idx');
            }
        });
    }

    protected function addOrderItemDimensions(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'tenant_id')) {
                $table->uuid('tenant_id')->nullable()->after('order_id');
            }

            if (!Schema::hasColumn('order_items', 'product_id')) {
                $table->uuid('product_id')->nullable()->after('variant_id');
            }
        });

        if (Schema::hasColumn('order_items', 'tenant_id')) {
            DB::table('order_items')
                ->whereNull('tenant_id')
                ->orderBy('id')
                ->chunkById(500, function ($items) {
                    $orderIds = collect($items)->pluck('order_id')->filter()->unique()->all();

                    if (empty($orderIds)) {
                        return;
                    }

                    $tenants = DB::table('orders')
                        ->whereIn('id', $orderIds)
                        ->pluck('tenant_id', 'id');

                    foreach ($items as $item) {
                        $tenantId = $tenants[$item->order_id] ?? null;
                        if ($tenantId) {
                            DB::table('order_items')
                                ->where('id', $item->id)
                                ->update(['tenant_id' => $tenantId]);
                        }
                    }
                }, 'id');
        }

        if (Schema::hasColumn('order_items', 'product_id')) {
            DB::table('order_items')
                ->whereNull('product_id')
                ->orderBy('id')
                ->chunkById(500, function ($items) {
                    $variantIds = collect($items)->pluck('variant_id')->filter()->unique()->all();

                    if (empty($variantIds)) {
                        return;
                    }

                    $products = DB::table('variants')
                        ->whereIn('id', $variantIds)
                        ->pluck('product_id', 'id');

                    foreach ($items as $item) {
                        $productId = $products[$item->variant_id] ?? null;
                        if ($productId) {
                            DB::table('order_items')
                                ->where('id', $item->id)
                                ->update(['product_id' => $productId]);
                        }
                    }
                }, 'id');
        }

        if (Schema::hasColumn('order_items', 'tenant_id')) {
            DB::statement('ALTER TABLE order_items ALTER COLUMN tenant_id SET NOT NULL');
        }

        if (Schema::hasColumn('order_items', 'product_id')) {
            DB::statement('ALTER TABLE order_items ALTER COLUMN product_id SET NOT NULL');
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'tenant_id')) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            }

            if (Schema::hasColumn('order_items', 'product_id')) {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            }

            if (!$this->indexExists('order_items', 'order_items_tenant_product_idx')) {
                $table->index(['tenant_id', 'product_id'], 'order_items_tenant_product_idx');
            }

            if (!$this->indexExists('order_items', 'order_items_tenant_order_idx')) {
                $table->index(['tenant_id', 'order_id'], 'order_items_tenant_order_idx');
            }
        });
    }

    protected function addPaymentTenantColumn(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'tenant_id')) {
                $table->uuid('tenant_id')->nullable()->after('order_id');
            }
        });

        if (Schema::hasColumn('payments', 'tenant_id')) {
            DB::table('payments')
                ->whereNull('tenant_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $orderIds = collect($rows)->pluck('order_id')->filter()->unique()->all();

                    if (empty($orderIds)) {
                        return;
                    }

                    $tenants = DB::table('orders')
                        ->whereIn('id', $orderIds)
                        ->pluck('tenant_id', 'id');

                    foreach ($rows as $row) {
                        $tenantId = $tenants[$row->order_id] ?? null;
                        if ($tenantId) {
                            DB::table('payments')
                                ->where('id', $row->id)
                                ->update(['tenant_id' => $tenantId]);
                        }
                    }
                }, 'id');
        }

        if (Schema::hasColumn('payments', 'tenant_id')) {
            DB::statement('ALTER TABLE payments ALTER COLUMN tenant_id SET NOT NULL');
        }

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'tenant_id')) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            }
        });
    }

    protected function addRefundTenantColumn(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('refunds', 'tenant_id')) {
                $table->uuid('tenant_id')->nullable()->after('order_id');
            }
        });

        if (Schema::hasColumn('refunds', 'tenant_id')) {
            DB::table('refunds')
                ->whereNull('tenant_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $orderIds = collect($rows)->pluck('order_id')->filter()->unique()->all();

                    if (empty($orderIds)) {
                        return;
                    }

                    $tenants = DB::table('orders')
                        ->whereIn('id', $orderIds)
                        ->pluck('tenant_id', 'id');

                    foreach ($rows as $row) {
                        $tenantId = $tenants[$row->order_id] ?? null;
                        if ($tenantId) {
                            DB::table('refunds')
                                ->where('id', $row->id)
                                ->update(['tenant_id' => $tenantId]);
                        }
                    }
                }, 'id');
        }

        if (Schema::hasColumn('refunds', 'tenant_id')) {
            DB::statement('ALTER TABLE refunds ALTER COLUMN tenant_id SET NOT NULL');
        }

        Schema::table('refunds', function (Blueprint $table) {
            if (Schema::hasColumn('refunds', 'tenant_id')) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            }
        });
    }

    protected function addSupportIndexes(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!$this->indexExists('orders', 'orders_tenant_store_created_idx')) {
                $table->index(['tenant_id', 'store_id', 'created_at'], 'orders_tenant_store_created_idx');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (!$this->indexExists('payments', 'payments_tenant_method_created_idx')) {
                $table->index(['tenant_id', 'method', 'created_at'], 'payments_tenant_method_created_idx');
            }
        });

        Schema::table('refunds', function (Blueprint $table) {
            if (!$this->indexExists('refunds', 'refunds_tenant_created_idx')) {
                $table->index(['tenant_id', 'created_at'], 'refunds_tenant_created_idx');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (!$this->indexExists('products', 'products_tenant_category_idx')) {
                $table->index(['tenant_id', 'menu_category_id'], 'products_tenant_category_idx');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        if (method_exists(Schema::getFacadeRoot(), 'hasIndex')) {
            return Schema::hasIndex($table, $index);
        }

        $connection = Schema::getConnection();
        $schemaManager = $connection->getDoctrineSchemaManager();
        $tableDetails = $schemaManager->listTableDetails($connection->getTablePrefix() . $table);

        return $tableDetails->hasIndex($index);
    }
};
