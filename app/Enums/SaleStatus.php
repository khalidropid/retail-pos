<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SaleStatus: string implements HasColor, HasLabel
{
    case Completed = 'completed';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Completed => 'مكتملة',
            self::PartiallyReturned => 'مرتجعة جزئيًا',
            self::Returned => 'مرتجعة',
            self::Cancelled => 'ملغاة',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::PartiallyReturned => 'warning',
            self::Returned => 'gray',
            self::Cancelled => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
