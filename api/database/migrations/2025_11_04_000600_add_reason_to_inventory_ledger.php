<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_ledger', 'reason')) {
                $table->string('reason')->default('manual_adjustment')->after('qty_delta');
            }
        });

        DB::table('inventory_ledger')->update([
            'reason' => DB::raw("COALESCE(reason, 'manual_adjustment')"),
        ]);
    }

    public function down(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_ledger', 'reason')) {
                $table->dropColumn('reason');
            }
        });
    }
};
