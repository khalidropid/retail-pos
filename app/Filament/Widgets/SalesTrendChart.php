<?php

namespace App\Filament\Widgets;

use App\Services\DashboardService;
use Filament\Widgets\ChartWidget;

class SalesTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'صافي المبيعات - آخر 14 يومًا';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return auth()->user()?->can('dashboard.financials') ?? false;
    }

    protected function getData(): array
    {
        $trend = app(DashboardService::class)->salesTrend();

        return [
            'datasets' => [['label' => 'صافي المبيعات', 'data' => $trend['values'], 'fill' => true, 'tension' => 0.3]],
            'labels' => $trend['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
