<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'cogs_amount')) {
                $table->decimal('cogs_amount', 12, 2)
                    ->default(0)
                    ->after('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'cogs_amount')) {
                $table->dropColumn('cogs_amount');
            }
        });
    }
};
