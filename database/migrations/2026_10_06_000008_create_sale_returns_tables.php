<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('returned_at');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('total', 18, 2);
            // Part of the return that reduced the invoice debt / part paid back.
            $table->decimal('debt_reduction', 18, 2)->default(0);
            $table->decimal('refund_amount', 18, 2)->default(0);
            $table->foreignId('refund_payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->string('reason', 255);
            $table->timestamps();

            $table->index(['returned_at']);
            $table->index(['customer_id', 'returned_at']);
        });

        DB::statement('ALTER TABLE sale_returns ADD CONSTRAINT sale_returns_amounts CHECK (total > 0 AND debt_reduction >= 0 AND refund_amount >= 0 AND debt_reduction + refund_amount = total)');

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('line_total', 18, 2);

            $table->index('sale_item_id');
        });

        DB::statement('ALTER TABLE sale_return_items ADD CONSTRAINT sale_return_items_qty CHECK (quantity > 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
