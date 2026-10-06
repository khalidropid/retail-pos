<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CashDirection: string implements HasColor, HasLabel
{
    case In = 'in';
    case Out = 'out';

    public function getLabel(): string
    {
        return match ($this) {
            self::In => 'داخل',
            self::Out => 'خارج',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::In => 'success',
            self::Out => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
