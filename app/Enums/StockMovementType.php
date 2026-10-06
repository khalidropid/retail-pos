<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasColor, HasLabel
{
    case Opening = 'opening';
    case PurchaseReceipt = 'purchase_receipt';
    case PurchaseReturn = 'purchase_return';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case SaleCancellation = 'sale_cancellation';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case StockCount = 'stock_count';
    case ManualReceive = 'manual_receive';
    case ManualIssue = 'manual_issue';

    public function getLabel(): string
    {
        return match ($this) {
            self::Opening => 'رصيد افتتاحي',
            self::PurchaseReceipt => 'استلام مشتريات',
            self::PurchaseReturn => 'مرتجع مشتريات',
            self::Sale => 'بيع',
            self::SaleReturn => 'مرتجع مبيعات',
            self::SaleCancellation => 'إلغاء بيع',
            self::AdjustmentIn => 'تسوية بالزيادة',
            self::AdjustmentOut => 'تسوية بالنقص',
            self::StockCount => 'جرد',
            self::ManualReceive => 'استلام يدوي',
            self::ManualIssue => 'صرف يدوي',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Opening => 'gray',
            self::PurchaseReceipt => 'success',
            self::PurchaseReturn => 'warning',
            self::Sale => 'danger',
            self::SaleReturn => 'info',
            self::SaleCancellation => 'warning',
            self::AdjustmentIn => 'success',
            self::AdjustmentOut => 'danger',
            self::StockCount => 'primary',
            self::ManualReceive => 'success',
            self::ManualIssue => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
