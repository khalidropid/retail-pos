<?php

namespace App\Services;

use App\Enums\CashDirection;
use App\Enums\CashMovementType;
use App\Enums\CustomerLedgerType;
use App\Exceptions\BusinessException;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;

class CustomerPaymentService
{
    public function __construct(
        private NumberingService $numbers,
        private CustomerAccountService $accounts,
        private CashRegisterService $registers,
        private AuditLogger $audit,
    ) {}

    /**
     * Payment taken at checkout and allocated to the new sale.
     * Must run inside the sale transaction (sale row already locked/new).
     */
    public function recordPosPayment(Sale $sale, PaymentMethod $method, string $amount, User $user, ?CashRegisterSession $session): CustomerPayment
    {
        $customer = $sale->customer_id ? Customer::query()->findOrFail($sale->customer_id) : null;
        $balanceBefore = $customer?->balance;

        $payment = CustomerPayment::query()->create([
            'number' => $this->numbers->next('customer_payment'),
            'customer_id' => $customer?->id,
            'payment_method_id' => $method->id,
            'cash_register_session_id' => $method->isCash() ? $session?->id : null,
            'amount' => $amount,
            'payment_date' => now(),
            'user_id' => $user->id,
            'source' => CustomerPayment::SOURCE_POS,
            'notes' => "دفعة عند البيع - فاتورة {$sale->number}",
        ]);

        $this->applyAllocation($payment, $sale, $amount);

        if ($customer) {
            $this->accounts->post($customer, CustomerLedgerType::Payment, '0', $amount,
                "دفعة {$payment->number} على الفاتورة {$sale->number}", $user, $payment);
            $payment->update(['balance_before' => $balanceBefore, 'balance_after' => $customer->balance]);
        }

        if ($method->isCash()) {
            if (! $session) {
                throw new BusinessException('يجب فتح صندوق لاستلام دفعة نقدية.');
            }
            $this->registers->record($session, CashMovementType::Sale, CashDirection::In, $amount, $user, $sale,
                "مبيعات - فاتورة {$sale->number}");
        }

        return $payment;
    }

    /** Apply an allocation to a (locked) sale and refresh its derived fields. */
    protected function applyAllocation(CustomerPayment $payment, Sale $sale, string $amount): PaymentAllocation
    {
        if (Money::gt($amount, $sale->balance_due)) {
            throw new BusinessException("المبلغ المخصص للفاتورة {$sale->number} (".money($amount).') أكبر من المتبقي عليها ('.money($sale->balance_due).').');
        }

        $allocation = PaymentAllocation::query()->create([
            'customer_payment_id' => $payment->id,
            'sale_id' => $sale->id,
            'amount' => $amount,
        ]);

        $sale->paid_amount = Money::add($sale->paid_amount, $amount);
        $sale->recalculate();
        $sale->save();

        return $allocation;
    }
}
