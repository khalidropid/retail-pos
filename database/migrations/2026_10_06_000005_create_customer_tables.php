<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('name_normalized', 200);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('tax_number', 50)->nullable();
            // 0 = credit sales not allowed for this customer.
            $table->decimal('credit_limit', 18, 2)->default(0);
            // NULL = use the store default (credit.default_days).
            $table->unsignedSmallInteger('credit_days')->nullable();
            $table->text('notes')->nullable();
            // Outstanding receivable. Only CustomerAccountService writes it.
            $table->decimal('balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('CREATE INDEX customers_name_trgm ON customers USING gin (name_normalized gin_trgm_ops)');
        DB::statement('CREATE UNIQUE INDEX customers_phone_unique ON customers (phone) WHERE phone IS NOT NULL AND deleted_at IS NULL');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_non_negative CHECK (credit_limit >= 0)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_balance_non_negative CHECK (balance >= 0)');

        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('description', 255);
            // debit = customer owes more (sale, refund paid out, payment reversal)
            // credit = customer owes less (payment, return, cancellation)
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('balance_after', 18, 2);
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('entry_date');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'entry_date', 'id']);
        });

        DB::statement('ALTER TABLE customer_ledger_entries ADD CONSTRAINT customer_ledger_amounts CHECK (debit >= 0 AND credit >= 0 AND (debit > 0 OR credit > 0) AND NOT (debit > 0 AND credit > 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger_entries');
        Schema::dropIfExists('customers');
    }
};
