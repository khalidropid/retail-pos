<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RecordStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'فعّال',
            self::Voided => 'ملغى / معكوس',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Voided => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
