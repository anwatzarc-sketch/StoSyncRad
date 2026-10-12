<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Landed-cost allocations (Chapter 07 §7.9): append-only record of each cost distributed over receipt lines. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_cost_allocations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('allocation_group_id');
            $t->uuid('goods_receipt_id');
            $t->uuid('goods_receipt_line_id');
            $t->uuid('stock_movement_line_id');
            $t->uuid('item_id');
            $t->string('description', 200);
            $t->string('currency', 3)->nullable();
            $t->decimal('allocated_value', 20, 4);
            $t->decimal('capitalized_value', 20, 4);
            $t->decimal('expensed_value', 20, 4);
            $t->uuid('created_by')->nullable();
            $t->timestampTz('created_at');
            $t->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->restrictOnDelete();
            $t->foreign('goods_receipt_line_id')->references('id')->on('goods_receipt_lines')->restrictOnDelete();
            $t->index('goods_receipt_id');
            $t->index('allocation_group_id');
        });
        Pg::check('landed_cost_allocations', 'chk_lca_values', 'allocated_value >= 0 AND capitalized_value >= 0 AND expensed_value >= 0');
        Pg::appendOnly('landed_cost_allocations');
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
    }
};
