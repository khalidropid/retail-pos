<?php

use App\Services\SettingsService;
use App\Support\Money;

if (! function_exists('settings')) {
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $service = app(SettingsService::class);

        return $key === null ? $service : $service->get($key, $default);
    }
}

if (! function_exists('money')) {
    function money(mixed $amount, bool $withCurrency = true): string
    {
        return Money::format($amount, $withCurrency);
    }
}
