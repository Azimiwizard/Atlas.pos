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
            $table->decimal('qty_delta', 12, 3)->default(0)->after('variant_id');
            $table->foreignUuid('user_id')->nullable()->after('ref_id')->constrained('users')->nullOnDelete();
            $table->text('note')->nullable()->after('user_id');
        });

        DB::table('inventory_ledger')->update([
            'qty_delta' => DB::raw('COALESCE(delta, 0)'),
            'note' => DB::raw('COALESCE(reason, \'\')'),
        ]);

        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropColumn(['delta', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->decimal('delta', 12, 3)->default(0)->after('variant_id');
            $table->string('reason')->nullable()->after('delta');
        });

        DB::table('inventory_ledger')->update([
            'delta' => DB::raw('qty_delta'),
            'reason' => DB::raw('COALESCE(note, \'\')'),
        ]);

        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropColumn(['qty_delta', 'note']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
