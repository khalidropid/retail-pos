<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('sale_date');
            $table->string('sale_type', 20);
            $table->string('status', 30)->default('completed');
            $table->string('payment_status', 30);
            // subtotal = gross (qty x price) before discounts
            $table->decimal('subtotal', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('returned_amount', 18, 2)->default(0);
            $table->decimal('refunded_amount', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->decimal('amount_tendered', 18, 2)->nullable();
            $table->decimal('change_given', 18, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('credit_limit_overridden')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index('sale_date');
            $table->index(['customer_id', 'status']);
            $table->index(['user_id', 'sale_date']);
            $table->index(['payment_status', 'due_date']);
        });

        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amounts_non_negative CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total >= 0 AND paid_amount >= 0 AND returned_amount >= 0 AND refunded_amount >= 0 AND balance_due >= 0)');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_balance_formula CHECK (status = 'cancelled' OR balance_due = total - returned_amount - paid_amount + refunded_amount)");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_returned_le_total CHECK (returned_amount <= total)');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_credit_needs_customer CHECK (sale_type <> 'credit' OR (customer_id IS NOT NULL AND due_date IS NOT NULL))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_type_valid CHECK (sale_type IN ('cash', 'credit'))");

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Snapshots at time of sale.
            $table->string('product_name', 255);
            $table->string('sku', 64);
            $table->decimal('quantity', 15, 3);
            $table->decimal('returned_quantity', 15, 3)->default(0);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('list_price', 18, 2);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);

            $table->index('product_id');
        });

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_quantities CHECK (quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_amounts CHECK (unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)');

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamp('payment_date');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // pos = taken at checkout; account = later payment against debt
            $table->string('source', 20);
            $table->decimal('balance_before', 18, 2)->nullable();
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'payment_date']);
            $table->index(['status', 'payment_date']);
        });

        DB::statement('ALTER TABLE customer_payments ADD CONSTRAINT customer_payments_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE customer_payments ADD CONSTRAINT customer_payments_account_needs_customer CHECK (source = 'pos' OR customer_id IS NOT NULL)");

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['customer_payment_id', 'sale_id']);
            $table->index('sale_id');
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
