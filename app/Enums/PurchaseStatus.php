<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PurchaseStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Ordered => 'تم الطلب',
            self::PartiallyReceived => 'مستلم جزئيًا',
            self::Received => 'مستلم',
            self::Cancelled => 'ملغى',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ordered => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
