<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('promotions')) {
            return;
        }

        Schema::table('promotions', function (Blueprint $table) {
            if (!Schema::hasColumn('promotions', 'applies_to')) {
                $table->string('applies_to', 32)->default('all')->after('value');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('promotions') || !Schema::hasColumn('promotions', 'applies_to')) {
            return;
        }

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('applies_to');
        });
    }
};
