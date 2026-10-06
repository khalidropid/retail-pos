<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethodType: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::Card => 'بطاقة',
            self::BankTransfer => 'تحويل بنكي',
            self::Other => 'أخرى',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Card => 'info',
            self::BankTransfer => 'primary',
            self::Other => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
