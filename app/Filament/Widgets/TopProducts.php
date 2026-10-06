<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class TopProducts extends Widget
{
    protected static ?int $sort = 4;

    protected string $view = 'filament.widgets.top-products';

    public static function canView(): bool
    {
        return auth()->user()?->can('dashboard.financials') ?? false;
    }

    /** @return Collection<int, array<string, string>> */
    public function getProducts()
    {
        return app(ReportService::class)->topProducts(7, today()->startOfMonth()->toDateString(), today()->toDateString());
    }
}
