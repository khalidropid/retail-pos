<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_account_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 40)->unique();
            $table->string('provider', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_reference')->default(true);
            $table->boolean('available_at_pos')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('requires_account_type')->default(false)->after('type');
            $table->boolean('requires_reference')->default(false)->after('requires_account_type');
            $table->boolean('requires_verification')->default(false)->after('requires_reference');
            $table->boolean('available_at_pos')->default(true)->after('requires_verification');
        });

        // Snapshots keep historical records readable if a method or account
        // type is renamed or deactivated later.
        $details = function (Blueprint $table, string $prefix = '') {
            $table->string($prefix.'payment_method_name', 100)->nullable();
            $table->foreignId($prefix.'payment_account_type_id')->nullable()->constrained('payment_account_types')->restrictOnDelete();
            $table->string($prefix.'account_type_name', 100)->nullable();
        };

        Schema::table('customer_payments', function (Blueprint $table) use ($details) {
            $details($table);
            $table->string('verification_status', 20)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->index('reference');
        });
        Schema::table('supplier_payments', fn (Blueprint $table) => $details($table));
        Schema::table('expenses', fn (Blueprint $table) => $details($table));
        Schema::table('sale_returns', function (Blueprint $table) use ($details) {
            $details($table, 'refund_');
            $table->string('refund_reference', 100)->nullable();
        });

        // Backfill snapshots with the names in use BEFORE any renaming.
        foreach (['customer_payments' => 'payment_method_id', 'supplier_payments' => 'payment_method_id', 'expenses' => 'payment_method_id'] as $table => $fk) {
            DB::statement("UPDATE {$table} t SET payment_method_name = m.name FROM payment_methods m WHERE m.id = t.{$fk} AND t.payment_method_name IS NULL");
        }
        DB::statement('UPDATE sale_returns t SET refund_payment_method_name = m.name FROM payment_methods m WHERE m.id = t.refund_payment_method_id');

        $now = now();

        // Card becomes "Accounts" (electronic wallets / account-based payments).
        DB::table('payment_methods')->where('code', 'card')->update([
            'name' => 'الحسابات', 'type' => 'account', 'requires_account_type' => true, 'updated_at' => $now,
        ]);
        DB::table('payment_methods')->where('code', 'bank_transfer')->update([
            'name' => 'التحويلات البنكية', 'requires_reference' => true, 'requires_verification' => true, 'updated_at' => $now,
        ]);
        // The generic e-wallet method is superseded by Accounts + account type.
        DB::table('payment_methods')->where('code', 'wallet')->update(['is_active' => false, 'available_at_pos' => false, 'updated_at' => $now]);

        foreach (self::accountTypes() as $i => $type) {
            DB::table('payment_account_types')->insertOrIgnore($type + ['sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Yemeni e-wallets licensed by the Central Bank of Yemen (verified
     * 2026-10). "جيبلي" could not be verified and is inserted disabled.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function accountTypes(): array
    {
        return [
            ['code' => 'jawali', 'name' => 'جوالي', 'provider' => 'وي كاش', 'is_active' => true],
            ['code' => 'jaib', 'name' => 'جيب', 'provider' => 'شركة العهد المالية', 'is_active' => true],
            ['code' => 'cash_wallet', 'name' => 'كاش', 'provider' => 'شركة تمكين المالية', 'is_active' => true],
            ['code' => 'one_cash', 'name' => 'ون كاش', 'provider' => 'الشركة الوطنية للمحافظ الإلكترونية', 'is_active' => true],
            ['code' => 'mahfathati', 'name' => 'محفظتي', 'provider' => 'بنك التضامن', 'is_active' => true],
            ['code' => 'floosak', 'name' => 'فلوسك', 'provider' => 'بنك اليمن والكويت', 'is_active' => true],
            ['code' => 'm_floos', 'name' => 'إم فلوس', 'provider' => 'بنك الكريمي', 'is_active' => true],
            ['code' => 'jeebly', 'name' => 'جيبلي', 'provider' => null, 'is_active' => false,
                'notes' => 'لم يتم التحقق من وجود خدمة يمنية بهذا الاسم. تحقق قبل التفعيل.'],
        ];
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_payment_account_type_id');
            $table->dropColumn(['refund_payment_method_name', 'refund_account_type_name', 'refund_reference']);
        });
        foreach (['expenses', 'supplier_payments', 'customer_payments'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                $table->dropConstrainedForeignId('payment_account_type_id');
                $table->dropColumn(['payment_method_name', 'account_type_name']);
                if ($t === 'customer_payments') {
                    $table->dropConstrainedForeignId('verified_by');
                    $table->dropIndex(['reference']);
                    $table->dropColumn(['verification_status', 'verified_at']);
                }
            });
        }
        Schema::table('payment_methods', fn (Blueprint $table) => $table->dropColumn(['requires_account_type', 'requires_reference', 'requires_verification', 'available_at_pos']));
        Schema::dropIfExists('payment_account_types');
    }
};
