<?php

namespace Tests\Feature\Cash;

use App\Enums\CashMovementType;
use App\Exceptions\BusinessException;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Services\CashRegisterService;
use App\Services\ExpenseService;
use App\Services\PurchaseService;
use App\Services\SupplierPaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use BuildsDomainData;

    public function test_open_register_and_only_one_open_session_per_user_and_register(): void
    {
        $cashier = $this->userWithRole('cashier');
        $register = CashRegister::query()->create(['name' => 'الرئيسي']);
        $service = app(CashRegisterService::class);

        $session = $service->openRegister($register, '50000', $cashier);
        $this->assertTrue($session->isOpen());
        $this->assertStringStartsWith('REG-', $session->number);
        $this->assertDatabaseHas('audit_logs', ['event' => 'register_opened', 'user_id' => $cashier->id]);

        try {
            $service->openRegister(CashRegister::query()->create(['name' => 'ثاني']), 0, $cashier);
            $this->fail('A cashier cannot open two registers');
        } catch (BusinessException) {
        }

        $this->expectException(BusinessException::class);
        $service->openRegister($register, 0, $this->userWithRole('cashier'));
    }

    public function test_cash_in_out_and_close_with_difference(): void
    {
        $manager = $this->userWithRole('manager');
        $session = $this->openRegister($manager, '100000');
        $service = app(CashRegisterService::class);

        $service->cashIn($session, '20000', 'فكة', $manager);
        $service->cashOut($session, '5000', 'إيداع بنكي', $manager);
        $this->assertSame('115000.00', $service->expectedCash($session));

        $closed = $service->closeRegister($session, '114000', $manager, 'نقص ألف');

        $this->assertFalse($closed->isOpen());
        $this->assertSame('115000.00', $closed->expected_cash);
        $this->assertSame('114000.00', $closed->actual_cash);
        $this->assertSame('-1000.00', $closed->difference);
        $this->assertDatabaseHas('audit_logs', ['event' => 'register_closed']);
    }

    public function test_cannot_take_out_more_cash_than_in_drawer(): void
    {
        $manager = $this->userWithRole('manager');
        $session = $this->openRegister($manager, '1000');

        $this->expectException(BusinessException::class);
        app(CashRegisterService::class)->cashOut($session, '1500', 'x', $manager);
    }

    public function test_cashier_cannot_do_manual_cash_out(): void
    {
        $cashier = $this->userWithRole('cashier');
        $session = $this->openRegister($cashier, '1000');

        $this->expectException(AuthorizationException::class);
        app(CashRegisterService::class)->cashOut($session, '100', 'x', $cashier);
    }

    public function test_cannot_record_on_closed_session(): void
    {
        $manager = $this->userWithRole('manager');
        $session = $this->openRegister($manager, '1000');
        app(CashRegisterService::class)->closeRegister($session, '1000', $manager);

        $this->expectException(BusinessException::class);
        app(CashRegisterService::class)->cashIn($session->refresh(), '100', 'x', $manager);
    }

    public function test_cash_expense_creates_cash_movement_and_void_reverses_it(): void
    {
        $manager = $this->userWithRole('manager');
        $session = $this->openRegister($manager, '50000');
        $category = ExpenseCategory::query()->create(['name' => 'كهرباء']);
        $service = app(ExpenseService::class);

        $expense = $service->create([
            'expense_category_id' => $category->id, 'amount' => '12000', 'description' => 'فاتورة الكهرباء',
            'payment_method_id' => PaymentMethod::cash()->id,
        ], $manager);

        $this->assertSame($session->id, $expense->cash_register_session_id);
        $this->assertSame('38000.00', app(CashRegisterService::class)->expectedCash($session));

        $service->void($expense, 'مكرر', $manager);
        $this->assertSame('50000.00', app(CashRegisterService::class)->expectedCash($session));
        $this->assertSame(2, CashMovement::query()->where('reference_id', $expense->id)->count());
    }

    public function test_cash_expense_requires_open_register(): void
    {
        $manager = $this->userWithRole('manager');
        $category = ExpenseCategory::query()->create(['name' => 'ضيافة']);

        $this->expectException(BusinessException::class);
        app(ExpenseService::class)->create([
            'expense_category_id' => $category->id, 'amount' => '1000', 'description' => 'شاي',
            'payment_method_id' => PaymentMethod::cash()->id,
        ], $manager);
    }

    public function test_non_cash_expense_does_not_touch_register(): void
    {
        $manager = $this->userWithRole('manager');
        $category = ExpenseCategory::query()->create(['name' => 'إيجار']);

        $expense = app(ExpenseService::class)->create([
            'expense_category_id' => $category->id, 'amount' => '500000', 'description' => 'إيجار الشهر',
            'payment_method_id' => PaymentMethod::query()->where('code', 'bank_transfer')->value('id'),
            'reference' => 'TRX-5001',
        ], $manager);

        $this->assertNull($expense->cash_register_session_id);
        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_expense_cannot_be_deleted(): void
    {
        $manager = $this->userWithRole('manager');
        $expense = app(ExpenseService::class)->create([
            'expense_category_id' => ExpenseCategory::query()->create(['name' => 'x'])->id, 'amount' => '10', 'description' => 'x',
            'payment_method_id' => PaymentMethod::query()->where('code', 'card')->value('id'),
            'payment_account_type_id' => $this->walletTypeId(), 'reference' => 'W-778',
        ], $manager);

        $this->expectException(\LogicException::class);
        Expense::query()->findOrFail($expense->id)->delete();
    }

    public function test_cash_supplier_payment_goes_through_register(): void
    {
        $admin = $this->userWithRole('admin');
        $session = $this->openRegister($admin, '100000');
        $product = $this->stocked(0);
        $purchase = app(PurchaseService::class)->create(['supplier_id' => Supplier::factory()->create()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 3000]]], $admin);
        app(PurchaseService::class)->receive($purchase, [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 10]], $admin);

        $payment = app(SupplierPaymentService::class)->pay($purchase->supplier, '20000', PaymentMethod::cash()->id, $admin, $purchase->id);

        $this->assertSame($session->id, $payment->cash_register_session_id);
        $this->assertSame('80000.00', app(CashRegisterService::class)->expectedCash($session));
        $this->assertDatabaseHas('cash_movements', ['type' => CashMovementType::SupplierPayment->value, 'direction' => 'out', 'amount' => '20000.00']);

        app(SupplierPaymentService::class)->reverse($payment, 'خطأ', $admin);
        $this->assertSame('100000.00', app(CashRegisterService::class)->expectedCash($session));
    }

    public function test_cashier_cannot_view_another_cashiers_session(): void
    {
        $a = $this->userWithRole('cashier');
        $b = $this->userWithRole('cashier');
        $session = $this->openRegister($a, '0');

        $this->actingAs($b)->get("/admin/cash-register-sessions/{$session->id}")->assertNotFound();
        $this->actingAs($a)->get("/admin/cash-register-sessions/{$session->id}")->assertOk();
    }
}
