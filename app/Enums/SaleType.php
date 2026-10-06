<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SaleType: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case Credit = 'credit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'نقدي / مدفوع',
            self::Credit => 'آجل',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Credit => 'warning',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
