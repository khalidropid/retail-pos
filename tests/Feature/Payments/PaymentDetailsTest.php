<?php

namespace Tests\Feature\Payments;

use App\Exceptions\BusinessException;
use App\Filament\Pages\Pos;
use App\Filament\Resources\CustomerPayments\Pages\ListCustomerPayments;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentAccountType;
use App\Models\PaymentMethod;
use App\Services\CashRegisterService;
use App\Services\CustomerPaymentService;
use App\Services\ReportService;
use App\Services\ReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class PaymentDetailsTest extends TestCase
{
    use BuildsDomainData;

    private function cashier()
    {
        $user = $this->userWithRole('cashier');
        $this->openRegister($user, '0');

        return $user;
    }

    // ---------------------------------------------------------- accounts

    public function test_card_option_is_renamed_accounts_and_wallets_are_seeded(): void
    {
        $method = PaymentMethod::query()->where('code', 'card')->first();
        $this->assertSame('الحسابات', $method->name);
        $this->assertTrue($method->requires_account_type);

        $active = PaymentAccountType::query()->active()->pluck('name')->all();
        foreach (['جوالي', 'جيب', 'كاش', 'ون كاش', 'محفظتي'] as $name) {
            $this->assertContains($name, $active);
        }
        $this->assertFalse(PaymentAccountType::query()->where('code', 'jeebly')->value('is_active'), 'unverified wallet stays disabled');
    }

    public function test_accounts_payment_requires_account_type(): void
    {
        $cashier = $this->cashier();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('يجب اختيار نوع الحساب');
        $this->sell($cashier, [[$this->stocked(5), 1]], 'cash', null, ['payment_method_id' => $this->accountsMethodId()]);
    }

    public function test_account_type_and_reference_are_saved_on_the_payment(): void
    {
        $cashier = $this->cashier();
        $sale = $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '7000']), 1]], 'cash', null, [
            'payment_method_id' => $this->accountsMethodId(), 'account_type_id' => $this->walletTypeId('jaib'), 'payment_reference' => '  JB 99812 ',
        ]);

        $payment = $sale->allocations()->first()->payment;
        $this->assertSame('الحسابات', $payment->payment_method_name);
        $this->assertSame('جيب', $payment->account_type_name);
        $this->assertSame('JB 99812', $payment->reference, 'trimmed');
        $this->assertSame('الحسابات - جيب', $payment->methodLabel());
        $this->assertNull($payment->verification_status);
    }

    public function test_inactive_account_type_cannot_be_used_for_new_sales(): void
    {
        $cashier = $this->cashier();
        PaymentAccountType::query()->where('code', 'jawali')->update(['is_active' => false]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('نوع الحساب المختار غير متاح');
        $this->sell($cashier, [[$this->stocked(5), 1]], 'cash', null, [
            'payment_method_id' => $this->accountsMethodId(), 'account_type_id' => $this->walletTypeId('jawali'), 'payment_reference' => 'X-123',
        ]);
    }

    public function test_account_type_hidden_from_pos_when_not_available_there(): void
    {
        PaymentAccountType::query()->where('code', 'jawali')->update(['available_at_pos' => false]);
        $config = Livewire::actingAs($this->cashier())->test(Pos::class)->instance()->getConfig();

        $this->assertNotContains('جوالي', array_column($config['accountTypes'], 'name'));
        $this->assertContains('الحسابات', array_column($config['paymentMethods'], 'name'));
    }

    public function test_payment_details_appear_in_sale_receipt_and_payment_history(): void
    {
        $cashier = $this->cashier();
        $sale = $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '7000']), 1]], 'cash', null, [
            'payment_method_id' => $this->accountsMethodId(), 'account_type_id' => $this->walletTypeId('one_cash'), 'payment_reference' => 'OC-4411',
        ]);

        $this->actingAs($cashier)->get(route('print.sale-receipt', $sale))->assertOk()
            ->assertSee('الحسابات - ون كاش')->assertSee('OC-4411');

        $manager = $this->userWithRole('manager');
        Livewire::actingAs($manager)->test(ListCustomerPayments::class)
            ->filterTable('source', 'pos')
            ->assertSee('الحسابات - ون كاش')->assertSee('OC-4411');
        $this->actingAs($manager)->get("/admin/sales/{$sale->id}")->assertOk();
    }

    public function test_renaming_a_method_keeps_historical_names(): void
    {
        $cashier = $this->cashier();
        $sale = $this->sell($cashier, [[$this->stocked(5), 1]], 'cash', null, [
            'payment_method_id' => $this->accountsMethodId(), 'account_type_id' => $this->walletTypeId(), 'payment_reference' => 'J-1',
        ]);

        PaymentMethod::query()->where('code', 'card')->update(['name' => 'محافظ']);
        PaymentAccountType::query()->where('code', 'jawali')->update(['name' => 'جوالي الجديد']);

        $this->assertSame('الحسابات - جوالي', $sale->allocations()->first()->payment->methodLabel());
    }

    // ---------------------------------------------------- bank transfers

    public function test_bank_transfer_requires_reference(): void
    {
        $cashier = $this->cashier();

        try {
            $this->sell($cashier, [[$this->stocked(5), 1]], 'cash', null, ['payment_method_id' => $this->bankMethodId()]);
            $this->fail('transfer without reference accepted');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('رقم المرجع', $e->getMessage());
        }

        foreach (['   ', 'ab', str_repeat('9', 101)] as $bad) {
            try {
                $this->sell($cashier, [[$this->stocked(5), 1]], 'cash', null, ['payment_method_id' => $this->bankMethodId(), 'payment_reference' => $bad]);
                $this->fail("invalid reference '{$bad}' accepted");
            } catch (BusinessException) {
            }
        }
    }

    public function test_bank_reference_saved_pending_verification_and_verifiable(): void
    {
        $cashier = $this->cashier();
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '50000']), 1]], 'credit', $customer, ['paid_amount' => 0]);

        $payment = app(CustomerPaymentService::class)->recordCustomerPayment($customer, '20000', $this->bankMethodId(), $cashier, reference: "TRX-77\n120");

        $this->assertSame('TRX-77 120', $payment->reference);
        $this->assertSame(CustomerPayment::VERIFICATION_PENDING, $payment->verification_status);
        $this->assertSame('30000.00', $customer->refresh()->balance, 'payment counts toward the balance while pending');

        try {
            app(CustomerPaymentService::class)->verifyPayment($payment, $cashier);
            $this->fail('cashier verified a transfer');
        } catch (AuthorizationException) {
        }

        $manager = $this->userWithRole('manager');
        app(CustomerPaymentService::class)->verifyPayment($payment, $manager);
        $this->assertSame(CustomerPayment::VERIFICATION_VERIFIED, $payment->refresh()->verification_status);
        $this->assertSame($manager->id, $payment->verified_by);

        $this->actingAs($manager)->get(route('print.payment-receipt', $payment))->assertSee('TRX-77 120')->assertSee('مؤكد');
    }

    public function test_reference_is_kept_after_reversal_and_in_audit_log(): void
    {
        $cashier = $this->cashier();
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);
        $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '50000']), 1]], 'credit', $customer, ['paid_amount' => 0]);
        $payment = app(CustomerPaymentService::class)->recordCustomerPayment($customer, '20000', $this->bankMethodId(), $cashier, reference: 'BNK-555');

        app(CustomerPaymentService::class)->reversePayment($payment, 'تحويل مرتجع من البنك', $this->userWithRole('admin'));

        $this->assertSame('BNK-555', $payment->refresh()->reference);
        $log = AuditLog::query()->where('event', 'payment_reversed')->firstOrFail();
        $this->assertSame('BNK-555', $log->old_values['reference']);
        $this->assertSame('التحويلات البنكية', $log->old_values['method']);
    }

    public function test_references_are_not_globally_unique(): void
    {
        $cashier = $this->cashier();
        foreach ([1, 2] as $i) {
            $this->sell($cashier, [[$this->stocked(5), 1]], 'cash', null, ['payment_method_id' => $this->bankMethodId(), 'payment_reference' => 'SAME-REF']);
        }
        $this->assertSame(2, CustomerPayment::query()->where('reference', 'SAME-REF')->count());
    }

    // ---------------------------------------------------- split payments

    public function test_split_payment_cash_plus_wallet(): void
    {
        $cashier = $this->cashier();
        $session = app(CashRegisterService::class)->currentSession($cashier);
        $sale = $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '10000']), 1]], 'cash', null, [
            'payments' => [
                ['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '4000'],
                ['payment_method_id' => $this->accountsMethodId(), 'amount' => '6000', 'account_type_id' => $this->walletTypeId(), 'reference' => 'JW-1'],
            ],
            'amount_tendered' => '5000',
        ]);

        $this->assertSame('10000.00', $sale->paid_amount);
        $this->assertNull($sale->payment_method_id, 'mixed payment has no single method');
        $this->assertSame(2, $sale->allocations()->count());
        $this->assertSame('1000.00', $sale->change_given, 'change is computed on the cash part only');
        $this->assertSame('4000.00', app(CashRegisterService::class)->expectedCash($session), 'only cash enters the drawer');
    }

    public function test_split_payment_must_cover_total_for_paid_sale(): void
    {
        $cashier = $this->cashier();

        $this->expectException(BusinessException::class);
        $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '10000']), 1]], 'cash', null, [
            'payments' => [
                ['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '4000'],
                ['payment_method_id' => $this->bankMethodId(), 'amount' => '3000', 'reference' => 'B-1'],
            ],
        ]);
    }

    public function test_credit_sale_with_split_down_payment_leaves_rest_as_debt(): void
    {
        $cashier = $this->cashier();
        $customer = Customer::factory()->create(['credit_limit' => '1000000']);

        $sale = $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '100000']), 1]], 'credit', $customer, [
            'payments' => [
                ['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '20000'],
                ['payment_method_id' => $this->bankMethodId(), 'amount' => '30000', 'reference' => 'BNK-10'],
            ],
        ]);

        $this->assertSame('50000.00', $sale->balance_due);
        $this->assertSame('50000.00', $customer->refresh()->balance);
        $this->assertSame(2, CustomerPayment::query()->where('customer_id', $customer->id)->count());
    }

    public function test_split_payment_through_pos_checkout(): void
    {
        $cashier = $this->cashier();
        $p = $this->stocked(5, ['sale_price' => '9000']);

        $result = Livewire::actingAs($cashier)->test(Pos::class)->instance()->checkout([
            'items' => [['id' => $p->id, 'quantity' => 1]],
            'saleType' => 'cash',
            'payments' => [
                ['methodId' => PaymentMethod::cash()->id, 'amount' => 3000],
                ['methodId' => $this->accountsMethodId(), 'amount' => 6000, 'accountTypeId' => $this->walletTypeId('cash_wallet'), 'reference' => 'C-77'],
            ],
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertSame(['نقدي', 'الحسابات - كاش'], CustomerPayment::query()->orderBy('id')->get()->map->methodLabel()->all());
    }

    public function test_refund_by_bank_transfer_requires_reference(): void
    {
        $manager = $this->userWithRole('manager');
        $this->openRegister($manager, '100000');
        $p = $this->stocked(5, ['sale_price' => '5000']);
        $sale = $this->sell($manager, [[$p, 1]]);

        try {
            app(ReturnService::class)->returnSale($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]], 'x', $manager, $this->bankMethodId());
            $this->fail('bank refund without reference');
        } catch (BusinessException) {
        }

        $return = app(ReturnService::class)->returnSale($sale, [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]], 'x', $manager,
            $this->bankMethodId(), null, 'RF-901');
        $this->assertSame('RF-901', $return->refund_reference);
        $this->assertSame('التحويلات البنكية', $return->refund_payment_method_name);
    }

    public function test_api_split_payment_and_validation(): void
    {
        $cashier = $this->cashier();
        Sanctum::actingAs($cashier);
        $p = $this->stocked(5, ['sale_price' => '5000']);

        $this->postJson('/api/v1/sales', [
            'sale_type' => 'cash',
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->accountsMethodId(), 'amount' => 5000]],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'يجب اختيار نوع الحساب عند الدفع بـ "الحسابات".']);

        $this->postJson('/api/v1/sales', [
            'sale_type' => 'cash',
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->accountsMethodId(), 'amount' => 5000, 'account_type_id' => $this->walletTypeId(), 'reference' => 'J-77']],
        ])->assertCreated()->assertJsonPath('data.payments.0.method', 'الحسابات - جوالي')->assertJsonPath('data.payments.0.reference', 'J-77');
    }

    public function test_payment_reports_include_account_type_and_reference(): void
    {
        $cashier = $this->cashier();
        $this->sell($cashier, [[$this->stocked(5, ['sale_price' => '7000']), 1]], 'cash', null, [
            'payment_method_id' => $this->accountsMethodId(), 'account_type_id' => $this->walletTypeId('jaib'), 'payment_reference' => 'JB-1',
        ]);

        $methods = app(ReportService::class)->paymentMethods(null, null);
        $this->assertSame('جيب', $methods['rows']->firstWhere('method', 'الحسابات')['account_type']);

        $tx = app(ReportService::class)->paymentTransactions(null, null);
        $this->assertSame('JB-1', $tx['rows']->first()['reference']);
    }

    public function test_account_types_screen_requires_permission(): void
    {
        $this->actingAs($this->userWithRole('admin'))->get('/admin/payment-account-types')->assertOk()->assertSee('جوالي');
        $this->actingAs($this->userWithRole('cashier'))->get('/admin/payment-account-types')->assertForbidden();
    }
}
