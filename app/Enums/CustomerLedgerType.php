<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CustomerLedgerType: string implements HasColor, HasLabel
{
    case Sale = 'sale';
    case Payment = 'payment';
    case SaleReturn = 'sale_return';
    case Refund = 'refund';
    case PaymentReversal = 'payment_reversal';
    case SaleCancellation = 'sale_cancellation';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sale => 'فاتورة بيع',
            self::Payment => 'دفعة',
            self::SaleReturn => 'مرتجع مبيعات',
            self::Refund => 'استرداد نقدي للعميل',
            self::PaymentReversal => 'عكس دفعة',
            self::SaleCancellation => 'إلغاء فاتورة',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sale => 'danger',
            self::Payment => 'success',
            self::SaleReturn => 'info',
            self::Refund => 'warning',
            self::PaymentReversal => 'danger',
            self::SaleCancellation => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
