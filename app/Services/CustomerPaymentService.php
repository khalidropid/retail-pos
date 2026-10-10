<?php

namespace App\Services;

use App\Enums\CashDirection;
use App\Enums\CashMovementType;
use App\Enums\CustomerLedgerType;
use App\Enums\RecordStatus;
use App\Exceptions\BusinessException;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Models\User;
use App\Services\Payments\PaymentDetails;
use App\Services\Payments\PaymentDetailsResolver;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    public function recordPosPayment(Sale $sale, PaymentDetails $details, string $amount, User $user, ?CashRegisterSession $session): CustomerPayment
    {
        $customer = $sale->customer_id ? Customer::query()->findOrFail($sale->customer_id) : null;
        $balanceBefore = $customer?->balance;
        $method = $details->method;

        $payment = CustomerPayment::query()->create([
            'number' => $this->numbers->next('customer_payment'),
            'customer_id' => $customer?->id,
            'payment_method_id' => $method->id,
            'cash_register_session_id' => $method->isCash() ? $session?->id : null,
            'amount' => $amount,
            'payment_date' => now(),
            'user_id' => $user->id,
            'source' => CustomerPayment::SOURCE_POS,
            'reference' => $details->reference,
            'verification_status' => $details->needsVerification() ? CustomerPayment::VERIFICATION_PENDING : null,
            'notes' => "دفعة عند البيع - فاتورة {$sale->number}",
        ] + $details->attributes());

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

    /**
     * Payment received later against the customer's debt (one receipt can
     * settle several invoices). No new invoice is created.
     *
     * @param  array<int|string, string|int|float>|null  $allocations  sale_id => amount; null = oldest invoices first
     */
    public function recordCustomerPayment(
        Customer $customer,
        string|int|float $amount,
        int $paymentMethodId,
        User $user,
        ?array $allocations = null,
        ?string $reference = null,
        ?string $notes = null,
        int|string|null $accountTypeId = null,
    ): CustomerPayment {
        if (! $user->can('customer_payments.create')) {
            throw new AuthorizationException('ليس لديك صلاحية تسجيل دفعات العملاء.');
        }

        $amount = Money::round($amount);
        if (! Money::isPositive($amount)) {
            throw new BusinessException('مبلغ الدفعة يجب أن يكون أكبر من صفر.');
        }

        $details = app(PaymentDetailsResolver::class)->resolve($paymentMethodId, $accountTypeId, $reference);

        return DB::transaction(function () use ($customer, $amount, $details, $user, $allocations, $notes) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $method = $details->method;

            if (Money::gt($amount, $customer->balance)) {
                throw new BusinessException('مبلغ الدفعة ('.money($amount).') أكبر من إجمالي دين العميل ('.money($customer->balance).').');
            }

            // Lock every open invoice of this customer, oldest due first.
            $openSales = Sale::query()->where('customer_id', $customer->id)->outstanding()
                ->orderByRaw('due_date NULLS FIRST')->orderBy('sale_date')->orderBy('id')
                ->lockForUpdate()->get()->keyBy('id');

            $plan = $allocations === null
                ? $this->fifoPlan($openSales, $amount)
                : $this->manualPlan($openSales, $allocations, $amount);

            $session = $method->isCash() ? $this->registers->requireOpenSession($user) : null;
            $balanceBefore = $customer->balance;

            $payment = CustomerPayment::query()->create([
                'number' => $this->numbers->next('customer_payment'),
                'customer_id' => $customer->id,
                'payment_method_id' => $method->id,
                'cash_register_session_id' => $session?->id,
                'amount' => $amount,
                'payment_date' => now(),
                'user_id' => $user->id,
                'source' => CustomerPayment::SOURCE_ACCOUNT,
                'reference' => $details->reference,
                'verification_status' => $details->needsVerification() ? CustomerPayment::VERIFICATION_PENDING : null,
                'notes' => $notes,
            ] + $details->attributes());

            foreach ($plan as $saleId => $allocated) {
                $this->applyAllocation($payment, $openSales->get($saleId), $allocated);
            }

            $numbers = $openSales->only(array_keys($plan))->pluck('number')->implode('، ');
            $this->accounts->post($customer, CustomerLedgerType::Payment, '0', $amount,
                "سند قبض {$payment->number}".($numbers ? " - تسديد: {$numbers}" : ''), $user, $payment);

            $payment->update(['balance_before' => $balanceBefore, 'balance_after' => $customer->balance]);

            if ($session) {
                $this->registers->record($session, CashMovementType::CustomerPayment, CashDirection::In, $amount, $user, $payment,
                    "سند قبض {$payment->number} - {$customer->name}");
            }

            $this->audit->log('customer_payment', $payment, null, [
                'number' => $payment->number, 'customer' => $customer->name, 'amount' => $amount,
                'allocations' => $plan, 'balance_before' => $balanceBefore, 'balance_after' => $customer->balance,
            ], userId: $user->id);

            return $payment->load('allocations.sale');
        });
    }

    /**
     * Suggested oldest-first distribution (for the UI).
     *
     * @return array<int, string> sale_id => amount
     */
    public function suggestAllocation(Customer $customer, string|int|float $amount): array
    {
        $open = Sale::query()->where('customer_id', $customer->id)->outstanding()
            ->orderByRaw('due_date NULLS FIRST')->orderBy('sale_date')->orderBy('id')->get()->keyBy('id');

        return $this->fifoPlan($open, Money::round($amount), strict: false);
    }

    /** @return array<int, string> */
    private function fifoPlan(Collection $openSales, string $amount, bool $strict = true): array
    {
        $remaining = $amount;
        $plan = [];
        foreach ($openSales as $sale) {
            if (! Money::isPositive($remaining)) {
                break;
            }
            $take = Money::min($remaining, $sale->balance_due);
            $plan[$sale->id] = $take;
            $remaining = Money::sub($remaining, $take);
        }

        if ($strict && Money::isPositive($remaining)) {
            throw new BusinessException('لا توجد فواتير مفتوحة كافية لتوزيع كامل المبلغ.');
        }

        return $plan;
    }

    /** @return array<int, string> */
    private function manualPlan(Collection $openSales, array $allocations, string $amount): array
    {
        $plan = [];
        $sum = '0';
        foreach ($allocations as $saleId => $value) {
            $value = Money::round($value);
            if (Money::isZero($value)) {
                continue;
            }
            if (Money::isNegative($value)) {
                throw new BusinessException('مبلغ التوزيع لا يمكن أن يكون سالبًا.');
            }

            $sale = $openSales->get((int) $saleId)
                ?? throw new BusinessException('إحدى الفواتير المختارة لا تخص هذا العميل أو ليس عليها رصيد مستحق.');

            if (Money::gt($value, $sale->balance_due)) {
                throw new BusinessException('لا يمكن توزيع '.money($value)." على الفاتورة {$sale->number}، المتبقي عليها ".money($sale->balance_due).'.');
            }

            $plan[$sale->id] = $value;
            $sum = Money::add($sum, $value);
        }

        if (! Money::eq($sum, $amount)) {
            throw new BusinessException('مجموع المبالغ الموزعة ('.money($sum).') يجب أن يساوي مبلغ الدفعة ('.money($amount).').');
        }

        return $plan;
    }

    /**
     * Void a payment: never deleted, every effect is reversed with new
     * records (allocations released, ledger debit, cash out) and audited.
     */
    public function reversePayment(CustomerPayment $payment, string $reason, User $user): CustomerPayment
    {
        if (! $user->can('customer_payments.reverse')) {
            throw new AuthorizationException('ليس لديك صلاحية عكس دفعات العملاء.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('يجب ذكر سبب عكس الدفعة.');
        }

        if ($payment->customer_id === null) {
            throw new BusinessException('دفعة بيع نقدي بدون عميل لا تُعكس منفردة. ألغِ الفاتورة نفسها بدلًا من ذلك.');
        }

        return DB::transaction(function () use ($payment, $reason, $user) {
            // Lock order: customer -> payment -> sales -> register.
            $customer = Customer::query()->withTrashed()->lockForUpdate()->findOrFail($payment->customer_id);
            $payment = CustomerPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->isVoided()) {
                throw new BusinessException('هذه الدفعة معكوسة مسبقًا.');
            }
            $allocations = $payment->allocations()->get();
            $sales = Sale::query()->whereIn('id', $allocations->pluck('sale_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $sale = $sales->get($allocation->sale_id);
                if ($sale->isCancelled()) {
                    continue;
                }
                $sale->paid_amount = Money::sub($sale->paid_amount, $allocation->amount);
                $sale->recalculate();
                // A reopened cash invoice becomes due immediately so it shows up in overdue tracking.
                $sale->due_date ??= today();
                $sale->save();
            }

            $this->accounts->post($customer, CustomerLedgerType::PaymentReversal, $payment->amount, '0',
                "عكس سند القبض {$payment->number}: {$reason}", $user, $payment);

            if ($payment->cash_register_session_id) {
                $original = CashRegisterSession::query()->findOrFail($payment->cash_register_session_id);
                $session = $original->isOpen() ? $original : $this->registers->requireOpenSession($user);
                $this->registers->record($session, CashMovementType::PaymentReversal, CashDirection::Out, $payment->amount, $user, $payment,
                    "عكس سند القبض {$payment->number}");
            }

            $payment->update([
                'status' => RecordStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ]);

            $this->audit->log('payment_reversed', $payment, [
                'status' => 'active', 'amount' => $payment->amount,
                'method' => $payment->methodLabel(), 'reference' => $payment->reference,
                'verification_status' => $payment->verification_status,
            ],
                ['status' => 'voided', 'customer_balance' => $customer->balance], $reason, userId: $user->id);

            return $payment;
        });
    }

    /**
     * Mark a pending transfer as verified (the money really arrived). The
     * payment counts toward the balance either way; this records the check.
     */
    public function verifyPayment(CustomerPayment $payment, User $user, ?string $notes = null): CustomerPayment
    {
        if (! $user->can('customer_payments.verify')) {
            throw new AuthorizationException('ليس لديك صلاحية تأكيد التحويلات.');
        }

        return DB::transaction(function () use ($payment, $user, $notes) {
            $payment = CustomerPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->isVoided()) {
                throw new BusinessException('لا يمكن تأكيد دفعة معكوسة.');
            }
            if ($payment->verification_status !== CustomerPayment::VERIFICATION_PENDING) {
                throw new BusinessException('هذه الدفعة لا تحتاج تأكيدًا أو تم تأكيدها مسبقًا.');
            }

            $payment->update([
                'verification_status' => CustomerPayment::VERIFICATION_VERIFIED,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]);

            $this->audit->log('payment_verified', $payment, ['verification_status' => 'pending'],
                ['verification_status' => 'verified', 'reference' => $payment->reference], $notes, userId: $user->id);

            return $payment;
        });
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
