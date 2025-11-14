<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('store_id')->nullable()->after('tenant_id')->constrained('stores')->nullOnDelete();
        });

        Schema::table('registers', function (Blueprint $table) {
            $table->foreignUuid('store_id')->nullable()->after('tenant_id')->constrained('stores')->nullOnDelete();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignUuid('store_id')->nullable()->after('tenant_id')->constrained('stores')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUuid('store_id')->nullable()->after('tenant_id')->constrained('stores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('registers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
