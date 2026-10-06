<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SupplierLedgerType: string implements HasColor, HasLabel
{
    case PurchaseReceipt = 'purchase_receipt';
    case Payment = 'payment';
    case PurchaseReturn = 'purchase_return';
    case PaymentReversal = 'payment_reversal';

    public function getLabel(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'فاتورة مورد (استلام)',
            self::Payment => 'دفعة للمورد',
            self::PurchaseReturn => 'مرتجع مشتريات',
            self::PaymentReversal => 'عكس دفعة',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'danger',
            self::Payment => 'success',
            self::PurchaseReturn => 'info',
            self::PaymentReversal => 'warning',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
