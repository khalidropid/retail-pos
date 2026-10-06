<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->string('type', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('name_normalized', 200)->index();
            $table->string('phone', 30)->nullable()->index();
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->text('notes')->nullable();
            // Amount we owe the supplier. Only SupplierAccountService writes it.
            $table->decimal('balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('description', 255);
            // credit = we owe more (purchase receipt); debit = we owe less (payment/return)
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('balance_after', 18, 2);
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('entry_date');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supplier_id', 'entry_date', 'id']);
        });

        DB::statement('ALTER TABLE supplier_ledger_entries ADD CONSTRAINT supplier_ledger_amounts CHECK (debit >= 0 AND credit >= 0 AND (debit > 0 OR credit > 0) AND NOT (debit > 0 AND credit > 0))');

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('supplier_invoice_number', 100)->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('received_total', 18, 2)->default(0);
            $table->decimal('returned_total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'order_date']);
        });

        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_amounts_non_negative CHECK (subtotal >= 0 AND tax_amount >= 0 AND total >= 0 AND received_total >= 0 AND returned_total >= 0 AND paid_amount >= 0)');
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_returned_le_received CHECK (returned_total <= received_total)');

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->default(0);
            $table->decimal('returned_quantity', 15, 3)->default(0);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 18, 2);
        });

        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_quantities CHECK (quantity > 0 AND received_quantity >= 0 AND received_quantity <= quantity AND returned_quantity >= 0 AND returned_quantity <= received_quantity AND unit_cost >= 0)');

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('received_at');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('line_total', 18, 2);
        });

        DB::statement('ALTER TABLE purchase_receipt_items ADD CONSTRAINT purchase_receipt_items_qty CHECK (quantity > 0)');

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('returned_at');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->string('reason', 255);
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('line_total', 18, 2);
        });

        DB::statement('ALTER TABLE purchase_return_items ADD CONSTRAINT purchase_return_items_qty CHECK (quantity > 0)');

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('cash_register_session_id')->nullable()->index();
            $table->decimal('amount', 18, 2);
            $table->timestamp('payment_date');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'payment_date']);
        });

        DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        foreach (['supplier_payments', 'purchase_return_items', 'purchase_returns', 'purchase_receipt_items', 'purchase_receipts', 'purchase_items', 'purchases', 'supplier_ledger_entries', 'suppliers', 'payment_methods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
