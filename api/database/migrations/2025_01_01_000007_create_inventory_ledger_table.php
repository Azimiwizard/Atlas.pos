<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('variant_id')->constrained('variants')->cascadeOnDelete();
            $table->uuid('store_id')->nullable();
            $table->decimal('delta', 12, 3);
            $table->string('reason');
            $table->string('ref_type')->nullable();
            $table->uuid('ref_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledger');
    }
};

