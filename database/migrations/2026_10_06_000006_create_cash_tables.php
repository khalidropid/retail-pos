<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('location', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at');
            $table->decimal('opening_balance', 18, 2);
            $table->text('opening_notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('expected_cash', 18, 2)->nullable();
            $table->decimal('actual_cash', 18, 2)->nullable();
            $table->decimal('difference', 18, 2)->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'opened_at']);
        });

        // At most one open session per register and per cashier.
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_register ON cash_register_sessions (cash_register_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_user ON cash_register_sessions (user_id) WHERE status = 'open'");
        DB::statement('ALTER TABLE cash_register_sessions ADD CONSTRAINT cash_sessions_opening_non_negative CHECK (opening_balance >= 0)');
        DB::statement("ALTER TABLE cash_register_sessions ADD CONSTRAINT cash_sessions_closed_complete CHECK (status = 'open' OR (closed_at IS NOT NULL AND actual_cash IS NOT NULL AND expected_cash IS NOT NULL))");

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_session_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->string('direction', 3);
            $table->decimal('amount', 18, 2);
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cash_register_session_id', 'type']);
            $table->index('created_at');
        });

        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_direction CHECK (direction IN ('in', 'out'))");

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreign('cash_register_session_id')->references('id')->on('cash_register_sessions')->restrictOnDelete();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('description', 500);
            $table->date('expense_date');
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['expense_date', 'status']);
        });

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::table('supplier_payments', fn (Blueprint $table) => $table->dropForeign(['cash_register_session_id']));
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_register_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
