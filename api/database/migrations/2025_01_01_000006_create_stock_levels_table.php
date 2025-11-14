<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('variant_id')->constrained('variants')->cascadeOnDelete();
            $table->uuid('store_id')->nullable();
            $table->decimal('qty_on_hand', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['variant_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
