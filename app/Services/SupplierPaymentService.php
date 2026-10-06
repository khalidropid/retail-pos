<?php

namespace App\Services;

use App\Enums\CashDirection;
use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
use App\Enums\SupplierLedgerType;
use App\Exceptions\BusinessException;
use App\Models\CashRegisterSession;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SupplierPaymentService
{
    public function __construct(
        private NumberingService $numbers,
        private SupplierAccountService $accounts,
        private CashRegisterService $registers,
        private AuditLogger $audit,
    ) {}

    public function pay(
        Supplier $supplier,
        string $amount,
        int $paymentMethodId,
        User $actor,
        ?int $purchaseId = null,
        ?string $reference = null,
        ?string $notes = null,
    ): SupplierPayment {
        if (! $actor->can('supplier_payments.create')) {
            throw new AuthorizationException('ليس لديك صلاحية تسجيل دفعات الموردين.');
        }

        $amount = Money::round($amount);
        if (! Money::isPositive($amount)) {
            throw new BusinessException('مبلغ الدفعة يجب أن يكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($supplier, $amount, $paymentMethodId, $actor, $purchaseId, $reference, $notes) {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $method = PaymentMethod::query()->where('is_active', true)->findOrFail($paymentMethodId);

            if (Money::gt($amount, $supplier->balance)) {
                throw new BusinessException('مبلغ الدفعة ('.money($amount).') أكبر من الرصيد المستحق للمورد ('.money($supplier->balance).').');
            }

            if ($purchaseId !== null) {
                $purchase = Purchase::query()->lockForUpdate()->where('supplier_id', $supplier->id)->findOrFail($purchaseId);
                if (Money::gt($amount, $purchase->amountDue())) {
                    throw new BusinessException('مبلغ الدفعة أكبر من المستحق على أمر الشراء ('.money($purchase->amountDue()).').');
                }
            }

            $session = $method->isCash() ? $this->registers->requireOpenSession($actor) : null;

            $payment = SupplierPayment::query()->create([
                'number' => $this->numbers->next('supplier_payment'),
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchaseId,
                'payment_method_id' => $method->id,
                'cash_register_session_id' => $session?->id,
                'amount' => $amount,
                'payment_date' => now(),
                'user_id' => $actor->id,
                'reference' => $reference,
                'notes' => $notes,
            ]);

            if (isset($purchase)) {
                $purchase->update(['paid_amount' => Money::add($purchase->paid_amount, $amount)]);
            }

            if ($session) {
                $this->registers->record($session, CashMovementType::SupplierPayment, CashDirection::Out, $amount, $actor, $payment,
                    "دفعة للمورد {$supplier->name} ({$payment->number})");
            }

            $this->accounts->post($supplier, SupplierLedgerType::Payment, $amount, '0',
                "دفعة {$payment->number}".($reference ? " - مرجع {$reference}" : ''), $actor, $payment);

            $this->audit->log('supplier_payment', $payment, null, ['number' => $payment->number, 'amount' => $amount], userId: $actor->id);

            return $payment;
        });
    }

    public function reverse(SupplierPayment $payment, string $reason, User $actor): SupplierPayment
    {
        if (! $actor->can('supplier_payments.reverse')) {
            throw new AuthorizationException('ليس لديك صلاحية عكس دفعات الموردين.');
        }
        if (trim($reason) === '') {
            throw new BusinessException('يجب ذكر سبب عكس الدفعة.');
        }

        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->isVoided()) {
                throw new BusinessException('هذه الدفعة معكوسة مسبقًا.');
            }

            $payment->update([
                'status' => RecordStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            if ($payment->cash_register_session_id) {
                $original = CashRegisterSession::query()->findOrFail($payment->cash_register_session_id);
                $session = $original->isOpen() ? $original : $this->registers->requireOpenSession($actor);
                $this->registers->record($session, CashMovementType::SupplierPaymentReversal, CashDirection::In, $payment->amount, $actor, $payment,
                    "عكس دفعة المورد {$payment->number}");
            }

            if ($payment->purchase_id) {
                $purchase = Purchase::query()->lockForUpdate()->findOrFail($payment->purchase_id);
                $purchase->update(['paid_amount' => Money::sub($purchase->paid_amount, $payment->amount)]);
            }

            $this->accounts->post($payment->supplier, SupplierLedgerType::PaymentReversal, '0', $payment->amount,
                "عكس الدفعة {$payment->number}: {$reason}", $actor, $payment);

            $this->audit->log('supplier_payment_reversed', $payment, ['status' => 'active'], ['status' => 'voided'], $reason, userId: $actor->id);

            return $payment;
        });
    }
}
