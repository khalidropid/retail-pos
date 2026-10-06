<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CashMovementType: string implements HasColor, HasLabel
{
    case Sale = 'sale';
    case CustomerPayment = 'customer_payment';
    case Refund = 'refund';
    case Expense = 'expense';
    case ExpenseVoid = 'expense_void';
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case PaymentReversal = 'payment_reversal';
    case SupplierPayment = 'supplier_payment';
    case SupplierPaymentReversal = 'supplier_payment_reversal';
    case SaleCancellation = 'sale_cancellation';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sale => 'مبيعات',
            self::CustomerPayment => 'دفعة عميل',
            self::Refund => 'استرداد',
            self::Expense => 'مصروف',
            self::ExpenseVoid => 'إلغاء مصروف',
            self::CashIn => 'إيداع نقدي',
            self::CashOut => 'سحب نقدي',
            self::PaymentReversal => 'عكس دفعة عميل',
            self::SupplierPayment => 'دفعة لمورد',
            self::SupplierPaymentReversal => 'عكس دفعة مورد',
            self::SaleCancellation => 'إلغاء بيع',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sale => 'success',
            self::CustomerPayment => 'success',
            self::Refund => 'danger',
            self::Expense => 'danger',
            self::ExpenseVoid => 'success',
            self::CashIn => 'success',
            self::CashOut => 'danger',
            self::PaymentReversal => 'danger',
            self::SupplierPayment => 'danger',
            self::SupplierPaymentReversal => 'success',
            self::SaleCancellation => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
