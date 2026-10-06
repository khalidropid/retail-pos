<?php

namespace Tests\Feature\Customers;

use App\Enums\CustomerLedgerType;
use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\CustomerAccountService;
use App\Services\CustomerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    public function test_cashier_can_create_customer_but_gets_default_credit_limit(): void
    {
        settings()->set('credit.default_limit', '100000');

        $customer = app(CustomerService::class)->create([
            'name' => 'أحمد علي', 'phone' => '07701234567', 'credit_limit' => '9999999',
        ], $this->userWithRole('cashier'));

        $this->assertSame('100000.00', $customer->credit_limit);
    }

    public function test_credit_limit_change_requires_permission_and_is_audited(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => '500000']);

        try {
            app(CustomerService::class)->update($customer, ['credit_limit' => '900000'], $this->userWithRole('cashier'));
            $this->fail('Cashier must not update customers');
        } catch (AuthorizationException) {
        }

        $manager = $this->userWithRole('manager');
        app(CustomerService::class)->update($customer, ['credit_limit' => '900000'], $manager);

        $log = AuditLog::query()->where('event', 'credit_limit_changed')->firstOrFail();
        $this->assertEquals('500000.00', $log->old_values['credit_limit']);
        $this->assertEquals('900000.00', $log->new_values['credit_limit']);
        $this->assertSame($manager->id, $log->user_id);
    }

    public function test_balance_cannot_be_set_through_update(): void
    {
        $customer = Customer::factory()->create();
        app(CustomerService::class)->update($customer, ['balance' => '123', 'name' => 'اسم معدل'], $this->userWithRole('admin'));

        $this->assertSame('0.00', $customer->refresh()->balance);
    }

    public function test_ledger_posting_updates_running_balance(): void
    {
        $customer = Customer::factory()->create();
        $admin = $this->userWithRole('admin');
        $accounts = app(CustomerAccountService::class);

        $accounts->post($customer, CustomerLedgerType::Sale, '100000', '0', 'فاتورة', $admin);
        $entry = $accounts->post($customer, CustomerLedgerType::Payment, '0', '30000', 'دفعة', $admin);

        $this->assertSame('70000.00', $entry->balance_after);
        $this->assertSame('70000.00', $customer->refresh()->balance);
    }

    public function test_balance_can_never_become_negative(): void
    {
        $customer = Customer::factory()->create();

        $this->expectException(BusinessException::class);
        app(CustomerAccountService::class)->post($customer, CustomerLedgerType::Payment, '0', '1', 'x', $this->userWithRole('admin'));
    }

    public function test_database_rejects_negative_balance_even_without_service(): void
    {
        $customer = Customer::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('customers')->where('id', $customer->id)->update(['balance' => -5]);
    }

    public function test_customer_with_debt_cannot_be_deleted(): void
    {
        $customer = Customer::factory()->create();
        app(CustomerAccountService::class)->post($customer, CustomerLedgerType::Sale, '5000', '0', 'فاتورة', $this->userWithRole('admin'));

        $this->expectException(BusinessException::class);
        app(CustomerService::class)->delete($customer->refresh(), $this->userWithRole('admin'));
    }

    public function test_available_credit_and_default_terms(): void
    {
        settings()->set('credit.default_days', 45);
        $customer = Customer::factory()->create(['credit_limit' => '500000', 'credit_days' => null]);
        app(CustomerAccountService::class)->post($customer, CustomerLedgerType::Sale, '400000', '0', 'x', $this->userWithRole('admin'));

        $this->assertSame('100000.00', $customer->refresh()->availableCredit());
        $this->assertSame(45, $customer->effectiveCreditDays());
    }

    public function test_arabic_search_by_name_and_phone(): void
    {
        $c = Customer::factory()->create(['name' => 'فاطمة الزهراء', 'phone' => '07801112233']);

        $this->assertTrue(Customer::search('فاطمه')->whereKey($c->id)->exists());
        $this->assertTrue(Customer::search('٠٧٨٠١١١')->whereKey($c->id)->exists());
    }

    public function test_cashier_cannot_see_debts_column_permission(): void
    {
        $this->assertFalse($this->userWithRole('cashier')->can('customers.view_debts'));
        $this->assertTrue($this->userWithRole('manager')->can('customers.view_debts'));
    }
}
